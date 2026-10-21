<?php

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$trustedProxies = $_SERVER['TRUSTED_PROXIES'] ?? $_ENV['TRUSTED_PROXIES'] ?? null;
if (!empty($trustedProxies)) {
    $proxiesArray = array_map('trim', explode(',', $trustedProxies));

    Request::setTrustedProxies(
        $proxiesArray,
        Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PREFIX
        | Request::HEADER_X_FORWARDED_AWS_ELB
    );
}

$trustedHosts = $_SERVER['TRUSTED_HOSTS'] ?? null;
if (!empty($trustedHosts)) {
    $hostsArray = array_map('trim', explode(',', $trustedHosts));
    Request::setTrustedHosts($hostsArray);
}

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
