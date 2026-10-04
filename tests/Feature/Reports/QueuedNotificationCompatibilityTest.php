<?php

use App\Notifications\Inventory\InventoryNotification;
use App\Notifications\Procurement\ProcurementNotification;

test('procurement and inventory notifications queued before the context argument still build a payload', function () {
    foreach ([ProcurementNotification::class, InventoryNotification::class] as $class) {
        $notification = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach (['kind' => 'inventory.low_stock', 'title' => 'Low stock', 'body' => 'Cement is low', 'url' => '/stock'] as $name => $value) {
            (new ReflectionProperty($class, $name))->setValue($notification, $value);
        }

        $payload = (new ReflectionMethod($class, 'payload'))->invoke($notification, new stdClass);

        expect($payload)->toMatchArray(['kind' => 'inventory.low_stock', 'title' => 'Low stock', 'body' => 'Cement is low', 'url' => '/stock']);
    }
});
