<?php $pageTitle = SITE_NAME . ' — Login'; ?>
<?php require VIEWS_DIR . '/layout/header.php'; ?>

<section class="form-page">
    <h1>Login</h1>

    <?php if (!empty($error)): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/login">
        <label for="username">Username</label>
        <input
            type="text"
            id="username"
            name="username"
            autocomplete="username"
            required
            autofocus
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
        >

        <label for="password">Password</label>
        <input
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            required
        >

        <button type="submit">Login</button>
    </form>
</section>

<?php require VIEWS_DIR . '/layout/footer.php'; ?>
