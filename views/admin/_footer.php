<?php
declare(strict_types=1);
$currentYear = (int)date('Y');
$copyrightYears = $currentYear > 2025 ? '2025-' . $currentYear : '2025';
?>
</main>
<footer style="padding:1.25rem;text-align:center;color:var(--muted);font-size:.9rem">
    &copy; <?= htmlspecialchars($copyrightYears, ENT_QUOTES, 'UTF-8') ?>
    <a href="mailto:hichamgi@gmail.com" style="color:inherit;text-decoration:none;font-weight:600">Hicham ARID</a>
</footer>
</div>
</body>
</html>
