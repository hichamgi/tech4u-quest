<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Database.php';
require_once dirname(__DIR__) . '/app/Core/Auth.php';

use App\Core\Auth;

Auth::logout();
header('Location: login.php');
exit;
