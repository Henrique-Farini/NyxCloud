<?php

declare(strict_types=1);

require_once __DIR__ . '/auth/middleware.php';
$usuario = usuarioAutenticado($pdo);

if ($usuario === null) {
    header('Location: index.php');
    exit;
}

?>

<!DOCTYPE html>
<html lang="pt-br">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Painel</title>

    <link rel="stylesheet" href="../assets/css/painel.css">
    <link rel="icon" type="image/x-icon" href="../assets/img/fav.ico">

</head>
<body>

<div class="painel-container">

    <div class="painel-card">

        <div class="badge">
            NYXCLOUD
        </div>

        <h1>
            Bem-vindo,
            <span>
                <?php echo htmlspecialchars($usuario['nome'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </h1>

        <p>
            Seu painel está pronto para uso.
            Gerencie backups, monitoramentos e dashboards em um único lugar.
        </p>

        <div class="painel-buttons">

            <a href="../painel-demo/" class="btn-primary">
                Acessar Painel
            </a>

            <a href="logout.php" class="btn-secondary">
                Sair
            </a>

        </div>

    </div>

</div>

</body>
</html>
