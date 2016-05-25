<?php
class Cache
{
    private static $instancia;
    protected $hashMap;
    protected $tiempoVida;
    protected $nombreArchivo;
    protected $tiempoActualizacionArchivo;
    protected $limite;
    protected $tamanio;
    protected $cantHits;
    protected $cantMiss;

    /**
     * Descripción: Singleton de la clase, impide crear dos instancias al
     * mismo tiempo de este objeto.
     *
     * @param int $campania id de la campania
     * @return cache Instancia de la clase
     *
     * @author Delle Donne, Sebastian
     *
     * @internal Fecha de creación: 2015-10-22
     * @internal Ultima modificación: 2015-10-22
     * @internal Razón: Creacion
     */
    public static function getInstance($campania = 0)
    {

        if( !self::$instancia instanceof self ){

            // Le saco el módulo para tener a lo sumo tantos archivos como indique el config.
            $campania = $campania % CANTIDAD_ARCHIVOS;
            self::$instancia = new self($campania);
        }
        return self::$instancia;
    }

    /**
     * Descripción: Constructor de la clase, levanta el archivo desde disco con
     * los datos de la cache si es que existe.
     *
     * @author Delle Donne, Sebastian | Machado, Martin
     *
     * @internal Fecha de creación: 2015-10-22
     * @internal Ultima modificación: 2015-10-22
     * @internal Razón: Creacion
     */
    private function __construct($campania)
    {
        $this->hashMap = array();
        $this->tiempoVida = TIEMPO_VIDA;
        $this->nombreArchivo = DIRECTORIO_CACHE.$campania."_cache.json";
        $fecha  = new DateTime();
        $this->limite = LIMITE;
        $this->tamanio = 0;
        $this->cantHits = 0;
        $this->cantMiss = 0;
        $this->cargarArchivo();

    }

    /**
     * Descripción: Destructor, persiste en disco la cache.
     *
     * @author Delle Donne, Sebastian
     *
     * @internal Fecha de creación: 2015-10-22
     * @internal Ultima modificación: 2015-10-22
     * @internal Razón: Creacion
     */
    function __destruct()
    {
        $this->persistirEnDisco();
    }

    /**
     * Descripción: Añade a la cache, el par pasado por parametro.
     *
     *
     * @author Delle Donne, Sebastian | Machado, Martin
     *
     * @internal Fecha de creación: 2015-10-22
     * @internal Ultima modificación: 2015-10-22
     * @internal Razón: Creacion
     */
    public function cachear($clave, $valor)
    {
        try {

            $this->cantMiss++;

            if ($this->tamanio >= LIMITE) {
                $this->liberarCache();
            }

            $hash = md5($clave);
            $fecha = new DateTime();

            if (!isset($this->hashMap[$hash]) || empty($this->hashMap[$hash])) {
                $this->hashMap[$hash] = array();
            }
            $this->hashMap[$hash][$clave]["valor"] = $valor;
            $this->hashMap[$hash][$clave]["timestamp"] = $fecha->getTimestamp();
            $this->tamanio++;

        } catch (Exception $e) {
            echo ("Excepcion".$e->getMessage()."\n".$e->getTrace());
        }
    }

    /**
     * Descripción: Permite obtener un elemento de la cache.
     * Primero se fija si se le venció la persistencia y luego lo devuelve.
     *
     * @return stdclass $respuesta o null si no existe o está vencido.
     *
     * @author Delle Donne, Sebastian | Machado, Martin
     *
     * @internal Fecha de creación: 2015-10-22
     * @internal Ultima modificación: 2015-10-22
     * @internal Razón: Creacion
     */
    public function leer($clave)
    {

        try {
            $fecha  = new DateTime();
            $hash   = md5($clave);
            $respuesta = null;
            if (isset($this->hashMap[$hash]) && !empty($this->hashMap[$hash])) {
                if (isset($this->hashMap[$hash][$clave]) && !empty($this->hashMap[$hash][$clave])) {
                   if ($this->hashMap[$hash][$clave]["timestamp"]+$this->tiempoVida < $fecha->getTimestamp()) {
                       unset($this->hashMap[$hash][$clave]);
                       if (empty($this->hashMap[$hash])) {
                            unset($this->hashMap[$hash]);
                       }
                       $this->tamanio--;
                   } else {
                       $respuesta = $this->hashMap[$hash][$clave]["valor"];
                       $this->cantHits++;
                   }
                }
            }
            return $respuesta;
        } catch (Exception $e) {
            echo ("Excepcion".$e->getMessage()."\n".$e->getTrace());
        }
    }

    /**
     * Descripción: Persiste en un archivo de disco el contenido de la cache.
     *
     *
     * @author Delle Donne, Sebastian | Machado, Martin
     *
     * @internal Fecha de creación: 2015-10-22
     * @internal Ultima modificación: 2015-10-22
     * @internal Razón: Creacion
     */
    protected function persistirEnDisco()
    {
        try {
            if (file_exists($this->nombreArchivo)) {
                @unlink($this->nombreArchivo);
            }

            // LOCK_EX para evitar que otros php's escriban el archivo al mismo tiempo.
            file_put_contents($this->nombreArchivo, json_encode($this->hashMap, LOCK_EX));

        } catch (Exception $e) {
            echo ("Excepcion".$e->getMessage()."\n".$e->getTrace());
        }
    }

    /**
     * Descripción: Trae a memoria principal los datos de la cache que se
     * persistieron en el archivo de disco.
     *
     * @author Delle Donne, Sebastian
     *
     * @internal Fecha de creación: 2015-10-22
     * @internal Ultima modificación: 2015-10-22
     * @internal Razón: Creacion
     */
    protected function cargarArchivo()
    {
        try {
            if (file_exists($this->nombreArchivo)){
                $json_data = file_get_contents($this->nombreArchivo);
                $this->hashMap = json_decode($json_data, true);
                $this->tamanio = count($this->hashMap);

                // Si queda vacío le hago un new para que no tire nullpointer si se accede
                if (is_null($this->hashMap) || empty($this->hashMap)) {
                    $this->hashMap = array();
                }
            }
        } catch (Exception $e) {
            echo ("Excepcion".$e->getMessage()."\n".$e->getTrace());
            $this->hashMap = array();
        }
    }

    /**
     * Descripción: Libera un porcentage de la cache.
     * Eliminando los items con tiempo de vida vencidos.
     * Si no se llego a elimar al menos el 35 porciento. Elimina los elementos
     * necesarios para llegar hasta el 35 porciento.
     *
     * @author Delle Donne, Sebastian
     * @param int $cantidad Cantidad de elementos a eliminar
     * @internal Fecha de creación: 2015-10-22
     * @internal Ultima modificación: 2015-10-22
     * @internal Razón: Creacion
     */
    protected function liberarCache()
    {
        try {

            $fecha  = new DateTime();
            $cantidadInicial = count($this->hashMap);

            // Por cada balde en el hashing
            foreach($this->hashMap as $hash => $balde) {

                // Por cada item en el balde
                foreach($balde as $query => $item){

                    // Si el tiempo de vida del item expiro lo elimino
                    if ($item["timestamp"] + $this->tiempoVida < $fecha->getTimestamp()) {
                        unset($this->hashMap[$hash][$query]);
                    }

                }

                // Si el balde se quedo sin items lo elimino
                if (empty($this->hashMap[$hash])) {
                    unset($this->hashMap[$hash]);
                }

            }

            $eliminados =  $cantidadInicial - count($this->hashMap);
            $aEliminar = ceil((35/100)*LIMITE);

            // si se elimino menos del 25 porciento elimino cualquiera hasta llegar al 25%.
            if ($eliminados < $aEliminar) {
                $this->hashMap = array_slice($this->hashMap,$aEliminar - $eliminados);
                $eliminados = $cantidadInicial - count($this->hashMap);
            }

            // Actualizo el tamanio del hashMap
            $this->tamanio = $this->tamanio - $eliminados;

        } catch (Exception $e) {
            echo("Excepcion".$e->getMessage()."\n".$e->getTrace());
        }
    }
}