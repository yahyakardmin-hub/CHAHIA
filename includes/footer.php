<?php if (function_exists('estAdmin') && !estAdmin()): ?>
    <?php require_once __DIR__ . '/chatbot.php'; ?>
<?php endif; ?>
<footer class="app-footer">GPAO Elevage &middot; CHAHIA &middot; <?= date('Y') ?></footer>
</body>
</html>