<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f0f0f">
    <meta name="description" content="Acesse o painel seguro da NyxCloud Solutions.">
    <title>Entrar | NyxCloud Solutions</title>
    <link rel="icon" type="image/x-icon" href="../assets/img/fav.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/login.css">
</head>
<body>
    <main class="auth-shell"> 
        <section class="brand-panel" aria-labelledby="brand-title">
            <div class="grid-glow" aria-hidden="true"></div><div class="orb orb-one" aria-hidden="true"></div><div class="orb orb-two" aria-hidden="true"></div>
            <div class="brand-content">
                <a class="brand" href="../index.html" aria-label="NyxCloud Solutions - página inicial"><img class="brand-logo" src="../assets/img/icone.png" alt=""><span>Nyx<span>Cloud</span></span></a>
                <div class="brand-copy">
                    <p class="eyebrow">Infraestrutura que não para</p>
                    <h1 id="brand-title">Seu negócio,<br><em>sempre online.</em></h1>
                    <p class="intro">Tecnologia inteligente para proteger, monitorar e impulsionar a operação da sua empresa.</p>
                </div>
                <div class="feature-grid" aria-label="Benefícios NyxCloud">
                    <div class="feature"><span class="feature-icon" aria-hidden="true">⌁</span><span><strong>Infraestrutura</strong><small>Escalável e confiável</small></span></div>
                    <div class="feature"><span class="feature-icon" aria-hidden="true">◈</span><span><strong>Segurança</strong><small>Proteção em cada camada</small></span></div>
                    <div class="feature"><span class="feature-icon" aria-hidden="true">↻</span><span><strong>Backup</strong><small>Dados sempre preservados</small></span></div>
                    <div class="feature"><span class="feature-icon" aria-hidden="true">≋</span><span><strong>Performance</strong><small>Agilidade para crescer</small></span></div>
                </div>
            </div>
        
            <p class="panel-footer">© <?= date('Y') ?> NyxCloud  <span>•</span> Tecnologia para o futuro</p>
        </section>
        <section class="form-panel" aria-labelledby="login-title">
            <div class="form-card">
                <div class="mobile-brand brand"><img class="brand-logo" src="../assets/img/icone.png" alt=""><span>Nyx<span>Cloud</span></span></div>
                <header class="form-header"><p class="eyebrow">Área do cliente</p><h2 id="login-title">Bem-vindo de volta</h2><p>Entre para acessar seu painel de controle.</p></header>
                <form action="api/login.php" method="POST" class="login-form" id="loginForm" novalidate>
                    <div class="field-group"><label for="email">E-mail / Usuário</label><div class="input-wrap"><span class="field-icon" aria-hidden="true">@</span><input type="email" name="email" id="email" placeholder="seu@email.com" autocomplete="username" required></div><small class="field-error" id="emailError"></small></div>
                    <div class="field-group"><label for="senha">Senha</label><div class="input-wrap"><span class="field-icon lock-small" aria-hidden="true">◆</span><input type="password" name="senha" id="senha" placeholder="Digite sua senha" autocomplete="current-password" required><button type="button" class="toggle-password" id="togglePassword" aria-label="Mostrar senha" aria-pressed="false"><svg class="eye-icon eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-5 9.5-5 9.5 5 9.5 5-3.5 5-9.5 5-9.5-5-9.5-5Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg><svg class="eye-icon eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 6.9A10.7 10.7 0 0 1 12 7c6 0 9.5 5 9.5 5a17 17 0 0 1-3.2 3.4M6.2 6.2C3.9 7.6 2.5 12 2.5 12s3.5 5 9.5 5c1.1 0 2.1-.2 3-.5"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg></button></div><small class="field-error" id="passwordError"></small></div>
                    <div class="form-options"><label class="remember"><input type="checkbox" name="lembrar" value="1"><span class="checkmark"></span>Lembrar de mim</label><a href="forgot-password.php" class="forgot-link" id="forgotPassword">Esqueci minha senha</a></div>
                    <button type="submit" class="submit-button" id="submitButton"><span class="button-label">Entrar</span><span class="button-loader" aria-hidden="true"></span></button><p class="form-message" id="loginMessage" role="alert" aria-live="polite"></p>
                </form>
                <div class="separator"><span>ou continue com</span></div><div class="social-grid"><button type="button" class="social-button" data-provider="Google"><span class="google-icon">G</span>Google</button><button type="button" class="social-button" data-provider="Microsoft"><span class="microsoft-icon"><i></i><i></i><i></i><i></i></span>Microsoft</button></div>
                <p class="security-note"><span class="shield-icon" aria-hidden="true">✦</span> Seus dados são protegidos com criptografia de ponta a ponta.</p>
            </div>
        </section>
    </main>
    <script src="../assets/js/login.js?v=20260817-1" defer></script>
</body>
</html>
