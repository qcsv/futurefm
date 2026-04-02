<?php $pageTitle = SITE_NAME . ' — Users'; ?>
<?php require VIEWS_DIR . '/layout/header.php'; ?>

<section class="admin-page">
    <h1>Users &amp; Invites</h1>

    <nav class="admin-nav">
        <a href="/admin">Dashboard</a>
        <a href="/admin/queue">Queue</a>
    </nav>

    <?php if (!empty($error)): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <p class="success"><?= htmlspecialchars($success) ?></p>
    <?php endif; ?>

    <!-- ── Users ──────────────────────────────────────────────── -->

    <h2>Users</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Active</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= htmlspecialchars($user['username']) ?></td>
                    <td><?= htmlspecialchars($user['email']) ?></td>
                    <td>
                        <form method="post" action="/admin/users/role">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <select name="role" onchange="this.form.submit()">
                                <option value="listener" <?= $user['role'] === 'listener' ? 'selected' : '' ?>>Listener</option>
                                <option value="admin"    <?= $user['role'] === 'admin'    ? 'selected' : '' ?>>Admin</option>
                            </select>
                        </form>
                    </td>
                    <td><?= $user['active'] ? 'Yes' : 'No' ?></td>
                    <td><?= date('Y-m-d', $user['created_at']) ?></td>
                    <td>
                        <form method="post" action="/admin/users/toggle">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <input type="hidden" name="active"  value="<?= $user['active'] ? '0' : '1' ?>">
                            <button type="submit">
                                <?= $user['active'] ? 'Suspend' : 'Activate' ?>
                            </button>
                        </form>
                        <form method="post" action="/admin/users/delete"
                              onsubmit="return confirm('Delete <?= htmlspecialchars($user['username']) ?>? This cannot be undone.')">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <button type="submit" class="btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ── Invite tokens ─────────────────────────────────────── -->

    <h2>Invite a User</h2>
    <form method="post" action="/admin/invite" class="inline-form">
        <input
            type="email"
            name="email"
            placeholder="Email address"
            required
        >
        <button type="submit">Generate Invite</button>
    </form>

    <h2>Outstanding Invites</h2>
    <?php if (empty($tokens)): ?>
        <p>No outstanding invites.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Created By</th>
                    <th>Expires</th>
                    <th>Used</th>
                    <th>Link</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tokens as $invite): ?>
                    <tr>
                        <td><?= htmlspecialchars($invite['email']) ?></td>
                        <td><?= htmlspecialchars($invite['created_by_username']) ?></td>
                        <td><?= date('Y-m-d H:i', $invite['expires_at']) ?></td>
                        <td><?= $invite['used'] ? 'Yes' : 'No' ?></td>
                        <td>
                            <?php if (!$invite['used'] && $invite['expires_at'] > time()): ?>
                                <code>/register?token=<?= htmlspecialchars($invite['token']) ?></code>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php require VIEWS_DIR . '/layout/footer.php'; ?>