<?php

test('MySQL and MariaDB connections create InnoDB tables regardless of the server default', function () {
    expect(config('database.connections.mysql.engine'))->toBe('InnoDB')
        ->and(config('database.connections.mariadb.engine'))->toBe('InnoDB');
});
