<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Database.php';

use App\Core\Database;

$db = Database::connection();

$id = 999999;
$classCode = 'DEMO';
$studentNumber = 1;
$loginCode = 'DEMO-01';
$password = '000000';

$stmt = $db->prepare(
    'INSERT INTO students(id,class_code,student_number,login_code,password_hash,must_change_password,active,updated_at)
     VALUES(:id,:class_code,:student_number,:login_code,:password_hash,0,1,CURRENT_TIMESTAMP)
     ON CONFLICT(id) DO UPDATE SET
        class_code=excluded.class_code,
        student_number=excluded.student_number,
        login_code=excluded.login_code,
        password_hash=excluded.password_hash,
        must_change_password=0,
        active=1,
        updated_at=CURRENT_TIMESTAMP'
);
$stmt->execute([
    'id' => $id,
    'class_code' => $classCode,
    'student_number' => $studentNumber,
    'login_code' => $loginCode,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
]);

echo "Compte de test prêt.\n";
echo "Login : demo-01\n";
echo "Mot de passe : 000000\n";
