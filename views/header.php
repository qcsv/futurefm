<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? SITE_NAME) ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <header class="site-header">
        <a href="/" class="site-name"><?= htmlspecialchars(SITE_NAME) ?></a>
        <nav class="site-nav">
            <?php if ($auth->isLoggedIn()): ?>
                <?php $currentUser = $auth->currentUser(); ?>
                <span class="nav-user"><?= htmlspecialchars($currentUser['username']) ?></span>
                <?php if ($auth->isAdmin()): ?>
                    <a href="/admin">Admin</a>
                <?php endif; ?>
                <a href="/logout">Logout</a>
            <?php else: ?>
                <a href="/login">Login</a>
            <?php endif; ?>
        </nav>
    </header>
    <main class="site-main">
