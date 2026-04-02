<?php $pageTitle = SITE_NAME . ' — Register'; ?>
<?php require VIEWS_DIR . '/layout/header.php'; ?>

<section class="form-page">
    <h1>Create Account</h1>
    <p>You were invited to join as <strong><?= htmlspecialchars($invite['email']) ?></strong>.</p>

    <?php if (!empty($error)): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/register">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

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
            autocomplete="new-password"
            required
        >

        <label for="confirm">Confirm Password</label>
        <input
            type="password"
            id="confirm"
            name="confirm"
            autocomplete="new-password"
            required
        >

        <button type="submit">Create Account</button>
    </form>
</section>

<?php require VIEWS_DIR . '/layout/footer.php'; ?>
