# 📬 FOSSBilling Support Module (Enhanced)  

Módulo de soporte extendido para FOSSBilling 8.2+. Añade gestión completa de tickets de soporte abiertos, respondidos y reabiertos, utilizando el correo electrónico (POP3 + SMTP), filtros de spam con Sieve y SpamAssassin, tickets de staff, y mucho más.

## ✨ Características

- ✅ **Tres tipos de tickets**: Para Clientes, Para Público general (invitados) y Usuarios de mesas de Staff (consultas internas desde el staff de soporte  a administradores de nivel superior).
- ✅ **Permite configuracion de una cuenta de correo por cada mesa de soporte** Lo que permite filtros personalizados e incluye formulario de configuracion pop3 y smtp y testeo de configuracion.
- ✅ **Agregar usuario desde un ticket publico a tu lista de clientes** ademas permite trasladar el hilo de soporte a la mesa de soporte seleccionada, para que el recien creado cliente pueda verlos desde su cuenta de cliente.
- ✅ **Email Gateway**: procesamiento automático de correos entrantes vía POP3 mediante Cron nativo de FOSSBillingy asignacion de ID de ticket.
- ✅ **Envío de respuestas** personalizado por cada mesa de soporte (SMTP)
- ✅ **Soporte para Sieve** (ManageSieve) y **SpamAssassin**
- ✅ **Whitelist / Blacklist** de correos por mesa
- ✅ **Gestión de palabras clave anti‑spam** con soporte para asunto, remitente, cuerpo y cabeceras
- ✅ **Menú "Mis Consultas"** para que el equipo de staff vea cada uno sus propios tickets abiertos al administrador de nivel superior.
- ✅ **Cabeceras Email-ID, in-reply-to y references**: Agregados en las cabeceras del correo y en la base de datos, lo que permite un mejor control de asignacion de hilo de soporte y evitan ser marcados como Spam por GMail, Outlook, etc.
- ✅ **Reapertura de tickets** al responder correos cerrados, incluso si se borra el ID del asunto
- ✅ **Limpieza y sanitizacion de citas** en respuestas (Gmail, Outlook, iPhone)
- ✅ **Auto‑detección de ruta** de buzones (DirectAdmin, cPanel, Plesk, ruta personalizada)
- ✅ **Panel de control de filtros** (Sieve / SpamAssassin) con botón de reinicio de filtros

## 📋 Requisitos

- **FOSSBilling 8.2** o superior
- **PHP 8.3** (requerido por FOSSBilling 8.2)
- **Dovecot** con ManageSieve habilitado (puerto 4190) **o** SpamAssassin instalado
- **Exim / Postfix** con buzones POP3 accesibles

⚠️ Aviso importante para los usuarios

Este módulo reemplaza al módulo Support original de FOSSBilling y debe instalarse reemplazando el modulo Support nativo de FOSSBillling 0.8.2 y/o después de tener FOSSBilling actualizado a la version 0.8.2, ya que necesita las tablas de base de datos creadas por la versión original.

## 🚀 Instalación

Este software se entrega tal y como esta, no nos hacemos responsables por cualquier fallo o daño resultante, bien por el propio software, o por un uso incorrecto o por no seguir las recomendaciones, tampoco proporcionamos soporte normal, prioritario o de cualquier otra clase.
**HAZ COPIA DE SEGURIDAD DE TODA TU INSTALACION FOSSBILLING Y LA BASE DE DATOS, ANTES DE INSTALAR ESTE MODULO.**
1. Descarga o clona este repositorio.
2. Copia la carpeta `Support` en `/modules/` de tu instalación FOSSBilling reemplazando la carpeta Support existente (haz copia de la carpeta Support antes de sobreescribirla).
3. El módulo creará automáticamente las tablas necesarias en la base de datos.

## ⚙️ Configuración

### 1. Crear mesas de soporte (Helpdesks)
- Ve a **Sistema → Ajustes → Soporte → Departamentos de soporte**.
- Crea una mesa para cada área (Soporte, Ventas, Staff, Publico etc.).
- En 📬 Configuración Email Gateway, haz clic en el selector Habilitar Recepción de Tickets por Email
- En el formulario que se muestra debajo del selector, Configura el **Email Gateway** (POP3 + SMTP) para empezar a recibir tickets por correo,  usa la misma cuenta de correo, de otra forma no se abriran tickets de soporte o se creara o romperan los hilos de soporte.
- Selecciona el **Nivel de accesibilidad** quien puede abrir tickets escribiendo al correo de la mesa de soporte, (Público, Clientes, Staff, Mixto).
- En 👥 Personal Asignado a Esta Mesa Usuarios de Soporte Que Atienden Esta Mesa
- Selecciona el **Personal de Staff** que se hara cargo de la mesa de soporte, para que aparezcan en la lista tienes que crear primero el personal de Staff en  **Sistema → Configuración → Staff → Personal**.
- En el pie de la creacion de la mesa de soporte veras la confirmacion de tablas creadas en el bloque 
⚠️ Instalación de Tablas de Soporte.
✅ Todas las tablas y columnas están instaladas correctamente.

### 2. Configurar filtros de spam
- El módulo detecta automáticamente si usas **Sieve** o **SpamAssassin**, si no puede detectarlos, en la creacion de la mesa de soporte, justo encima del bloque 👥 Personal Asignado a Esta Mesa hay un desplegable para seleccionar el tipo de filtro que usara el modulo, si no sabes cual tipo de filtro para el correo (Sieve o Spamassassin), usa el que el modulo usa por defecto.
- En **Soporte → Sieve Configuration** o **Soporte → SpamAssassin** puedes ver el estado y reiniciar filtros.

### 3. Configurar listas negras o blancas
- En **Soporte → Email Whitelist** o **Eamil → Blacklist** puedes permitir o bloquear correos por mesa de soporte.
- Una vez guardadas tus modificaciones en listas blancas o negras accede a **Soporte → Sieve Configuration** o **Soporte → SpamAssassin** y reinicia los filtros.

### 4. Asignar personal a mesas
- Al editar una mesa, selecciona los miembros del staff que podrán ver y responder sus tickets.

### 5. Ruta de buzones (solo SpamAssassin)
- Si usas SpamAssassin y el sistema no encuentra la ruta, ve a **Sistema → Configuración → Soporte** y selecciona tu panel de control (DirectAdmin, cPanel, Plesk) o escribe una ruta personalizada.

## 🛠️ Solución de problemas

### El cron no procesa correos entrantes
- Asegúrate de que el cron de FOSSBilling se ejecuta con **PHP 8.3**:
  ```bash
  /usr/local/php83/bin/php /ruta/a/FOSSBilling/cron.php
  
  markdown

    Verifica que los helpdesks tengan enable_email = 1 y credenciales POP3 correctas.

EN DIRECTADMIN
No llegan ni se envian correos de soporte y no se muestra nada en la pestaña de correos en espera.
Consulta tail -20 /var/log/exim/mainlog | grep "soporte@dominio.tld" o la cuenta de correo de la mesa de soporte afectada.
tambien puede estar el resultado en el directorio raiz de tu instalacion FOSSBilling tail -30 data/log/php_error.log (Si no ves resultados, tal vez tengas que activar la depuracion en tu archivo config.php).
Si muestra algo como 
451 Temporary local problem en los logs, o Error 451 al enviar respuestas o alguna referencia a auth_hit_limit_acl
Es un problema de Exim:
https://forum.directadmin.com/threads/please-help-exim-rejecting-all-incoming-emails-since-da-1-702-update-solved.82291/
En Directadmin, sucede que a veces queda una version residual de instalacion personalizada de exim, y o los modulos, en este caso perl, no estan actualizados o no se compilo bien la instalacion.
PRIMERO, HAZ COPIA DE TU ARCHIVO DE CONFIGURACION DE EXIM:
GENERALMENTE 
cp /etc/exim.conf /etc/exim.conf.backup 
Para verificar usa el comando 
ls /etc 
y busca el archivo exim.conf.backup.

Revisa  ls /usr/local/directadmin/custombuild/custom/exim
Si ves el archivo exim conf, guarda una copia de este archivo fuera del directorio custombuil y eliminalo de custombuild, O en su defecto editalo y busca el siguiente bloque y comentalo.

nano /usr/local/directadmin/custombuild/custom/exim/exim.conf
Teclas ctrl + w  e ingresa el termino auth_hit_limit_acl
El bloque tiene que verse algo parecido a lo siguiente:
  # If you've hit the limit, you can't send anymore. Requires exim.pl 17+
  #drop  message = AUTH_TOO_MANY
  #      condition = ${perl{auth_hit_limit_acl}}
  #      authenticated = *
  cuando este comentado usa teclas ctrl + s y ctrl  +  x
  cd /usr/local/directadmin/custombuild/ y ./build exim_conf o cd /usr/local/directadmin/ y ./build exim_conf
  Si no se reinicia automaticamente exim, usa sudo systemctl restart exim puedes ver si funciona correctamente usando sudo systemctl status exim
  
   Si no te resulta usa el hook exim_post.sh proporcionado en la carpeta tools/.
    
    🛠️ Crear e instalar el script de protección

Ejecuta estos comandos como root:

    Crear el directorio de hooks (si no existe)
    mkdir -p /usr/local/directadmin/scripts/custom

    Copia el archivo exim_post.sh que hay en la carpeta tools del modulo de soporte.
    O crear el archivo del script
    nano /usr/local/directadmin/scripts/custom/exim_post.sh
    Y Pega el siguiente contenido (copia todo el bloque):
    
    #!/bin/bash
    # Script para comentar automáticamente la regla auth_hit_limit_acl
    # después de que DirectAdmin actualice exim.conf

    # Comentar la línea condition...${perl{auth_hit_limit_acl}}
    sed -i 's/^[[:space:]]*condition[[:space:]]*=[[:space:]]*\${perl{auth_hit_limit_acl}}/#&/' /etc/exim.conf

    # Comentar la línea drop message = AUTH_TOO_MANY
    sed -i 's/^[[:space:]]*drop[[:space:]]*message[[:space:]]*=[[:space:]]*AUTH_TOO_MANY/#&/' /etc/exim.conf

    # Comentar la línea authenticated = * que sigue al drop comentado
    sed -i '/^#[[:space:]]*drop[[:space:]]*message[[:space:]]*=[[:space:]]*AUTH_TOO_MANY/{ n; s/^[[:space:]]*authenticated[[:space:]]*=[[:space:]]*\*/#&/ }' /etc/exim.conf

    # Reiniciar Exim para aplicar los cambios
    systemctl restart exim

    Guardar y salir (Ctrl+O, Enter, Ctrl+X).

    Dar permisos de ejecución
    
    chmod +x /usr/local/directadmin/scripts/custom/exim_post.sh

    Probar que funciona (opcional)
    
    /usr/local/directadmin/scripts/custom/exim_post.sh

    Luego verifica con:

    grep "auth_hit_limit_acl" /etc/exim.conf

    Deberías ver las líneas comentadas con #.

Con esto, cada vez que DirectAdmin regenere la configuración de Exim (por actualización o manualmente), el script se ejecutará automáticamente y mantendrá desactivada la regla problemática.
 
Los correos desde iPhone llegan con caracteres extraños

    El módulo ya incluye un sistema de sanitización que decodifica quoted-printable y convierte a UTF-8. Si persiste, verifica que el remitente no esté usando un charset poco común.

## 🧑‍💻 Créditos / Autores

- **Desarrollador principal:** [Víctor Fornés para Inversiones Forma SPA] (2026)
- **Contribuciones:** [DeepSeek AI](https://deepseek.com) (2026)

El módulo se publica bajo la licencia GNU AGPL‑3.0.
## ☕ Apoya este proyecto

Si este módulo te resulta útil, puedes invitarme a un café a través de PayPal:  
[https://paypal.me/inversionesformaspa](https://paypal.me/inversionesformaspa)  
¡Cualquier apoyo es bienvenido! 🙏

🤝 Contribuir

Las contribuciones son bienvenidas. Abre un issue o un pull request en este repositorio.
📄 Licencia

Este módulo se distribuye bajo la licencia GNU AGPL‑3.0 compatible con la licencia Apache 2.0 de FOSSBilling.
>>>>>>> 053bc1f (Versión inicial del módulo fossbilling de soporte extendido)
