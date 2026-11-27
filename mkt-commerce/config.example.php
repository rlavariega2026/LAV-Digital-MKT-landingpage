<?php
// Copiar fuera de public_html como lav-mkt-commerce-config.php. Nunca subir credenciales al repositorio.
// Activar solo después de probar Mercado Pago y validar cobertura, pesos y destinos.
return [
 'enabled' => false,
 'access_token' => '',
 'webhook_secret' => '',
 'merchant_id' => '',
 // Código postal => zona de distancia vial confirmada desde Cuernavaca.
 'postal_zones' => [],
 'express_postal_codes' => [],
 // Límite conservador de paquetes físicos por pedido; ajustar tras comprobar peso cobrable.
 'max_physical_packages' => 0,
];
