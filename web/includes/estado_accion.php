<?php
// Se incluye después de autenticar y de inicializar los mensajes de la pantalla.
require_once __DIR__.'/ServicioEstados.php';
$_SESSION['csrf_estados'] ??= bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['estado_accion'])) {
    try {
        if(!is_string($_POST['csrf_estados']??null) || !hash_equals($_SESSION['csrf_estados'],$_POST['csrf_estados']))throw new RuntimeException('Recargá la página antes de confirmar.');
        $id=filter_var($_POST['recurso_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id || !is_string($_POST['motivo']??null))throw new RuntimeException('Recurso o motivo no válido.');
        $actor=(int)get_logged_user()['id'];$servicio=new ServicioEstados($pdo);
        $accion=$_POST['estado_accion'];
        if(!isset($_POST['impacto_firma'])) {
            $estadoVistaPrevia=$servicio->previsualizar($estadoTipo,$id,$actor);
            $estadoVistaPrevia['accion']=$accion;$estadoVistaPrevia['id']=$id;$estadoVistaPrevia['motivo']=$_POST['motivo'];
        } else {
        if(!is_string($_POST['impacto_firma']))throw new RuntimeException('Vista previa no válida.');
        $firma=$_POST['impacto_firma'];
        if($accion==='cerrar' && $estadoTipo==='entidad') {
            $servicio->cerrarAdministracion($id,$actor,$_POST['motivo'],$firma);$success_msg='Cierre administrativo registrado. Se conserva el historial.';
        } elseif($accion==='reactivar_inscripciones' && $estadoTipo==='modulo') {
            $n=$servicio->reactivarInscripciones($id,$actor,$_POST['motivo'],$firma);$success_msg=$n.' inscripciones reactivadas. Se conservaron los cupos y la lista de espera.';
        } elseif(in_array($accion,['activar','desactivar'],true)) {
            if($estadoTipo==='administrador')$servicio->cambiarAdministrador($id,$accion==='activar',$actor,$_POST['motivo'],$firma);
            else $resultado=$servicio->cambiar($estadoTipo,$id,$accion==='activar',$actor,$_POST['motivo'],$firma);
            $success_msg='Estado actualizado.'.(isset($resultado)?' Reservas canceladas: '.$resultado['reservas'].'. Módulos suspendidos: '.$resultado['modulos'].'.':'');
        } else throw new RuntimeException('La acción no está disponible.');
        // Evitar repetir una mutación al recargar. Los mensajes se consumen una vez.
        $_SESSION['estado_resultado']=$success_msg;
        header('Location: '.basename($_SERVER['SCRIPT_NAME']));exit;
        }
    } catch(PDOException $e) {error_log('Estado MultiPro: '.$e->getMessage());$error_msg='No se pudo completar la operación. Revisá los pendientes, cupos y dependencias; no se guardaron cambios parciales.';}
      catch(RuntimeException $e) {$error_msg=htmlspecialchars($e->getMessage(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
if(isset($_SESSION['estado_resultado'])) {$success_msg=$_SESSION['estado_resultado'];unset($_SESSION['estado_resultado']);}
