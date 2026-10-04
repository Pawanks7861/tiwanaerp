<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Services\Notifications\FcmClient;
use Illuminate\Http\Response;

/**
 * Service worker that lets the browser receive FCM messages. The web config is public by
 * design; the service-account key never appears here.
 */
class FcmServiceWorkerController extends Controller
{
    public function __invoke(FcmClient $fcm): Response
    {
        $config = $fcm->webConfig();
        $script = $config === null
            ? "self.addEventListener('install', () => self.skipWaiting());\n"
            : $this->script($config);

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    /**
     * @param  array{apiKey: string, authDomain: string, projectId: string, messagingSenderId: string, appId: string, vapidKey: string}  $config
     */
    private function script(array $config): string
    {
        $json = json_encode([
            'apiKey' => $config['apiKey'],
            'authDomain' => $config['authDomain'],
            'projectId' => $config['projectId'],
            'messagingSenderId' => $config['messagingSenderId'],
            'appId' => $config['appId'],
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<JS
importScripts('https://www.gstatic.com/firebasejs/11.6.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/11.6.0/firebase-messaging-compat.js');
firebase.initializeApp({$json});
firebase.messaging();
JS;
    }
}
