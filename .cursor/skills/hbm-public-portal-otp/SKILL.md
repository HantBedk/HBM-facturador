---
name: hbm-public-portal-otp
description: >-
  Consultas públicas sin login con verificación NIT + OTP por correo: throttling,
  expiración corta, intentos máximos, logging por IP/NIT, respuestas genéricas en
  error para no filtrar existencia de datos.
---

# Portal público + OTP (HBM)

## Seguridad

- `throttle` en rutas `/public/*`.
- OTP: hash en BD, TTL 10–15 min, máximo 5 intentos por OTP.
- No revelar si el NIT existe o el correo coincide (mensaje neutro).
- Registrar `company_public_access_logs`: nit normalizado, ip, user_agent, acción (`otp_requested`, `otp_verified`, `inventory_list`).

## Laravel

- `Mail::fake()` en tests; producción: SMTP (ej. Brevo) vía `.env`.
- Tokens de sesión de portal: string aleatorio largo en cookie httpOnly o respuesta JSON + storage session frontend según diseño.
