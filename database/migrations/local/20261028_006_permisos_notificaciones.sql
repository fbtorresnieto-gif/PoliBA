-- Exclusivo staging local. Ejecutar junto con 006 usando el administrador local.
REVOKE ALL ON inscripcion_promociones,notificaciones_email FROM PUBLIC,multipro_app_local;
REVOKE ALL ON SEQUENCE inscripcion_promociones_id_seq,notificaciones_email_id_seq FROM PUBLIC,multipro_app_local;
REVOKE ALL ON password_reset_solicitudes FROM PUBLIC,multipro_app_local;
REVOKE ALL ON SEQUENCE password_reset_solicitudes_id_seq FROM PUBLIC,multipro_app_local;
GRANT SELECT,INSERT ON inscripcion_promociones TO multipro_app_local;
GRANT SELECT,INSERT,UPDATE ON notificaciones_email TO multipro_app_local;
GRANT SELECT,INSERT,UPDATE ON password_reset_solicitudes TO multipro_app_local;
GRANT USAGE ON SEQUENCE inscripcion_promociones_id_seq,notificaciones_email_id_seq,password_reset_solicitudes_id_seq TO multipro_app_local;
