#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 Víctor Fornés para Inversiones Forma SPA.
# Contributions by DeepSeek AI, 2026.
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
