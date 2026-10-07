<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

require_post_csrf();
Auth::logout();
flash('Du er logget ud.');
redirect('login.php');
