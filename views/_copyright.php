<?php
declare(strict_types=1);
$currentYear = (int)date('Y');
$copyrightYears = $currentYear > 2025 ? '2025-' . $currentYear : '2025';
$copyrightCompact = $copyrightCompact ?? true;
$footerStyle = $copyrightCompact
    ? 'margin-top:-2.25rem;padding:.5rem 1rem .85rem;text-align:center;color:var(--muted);font-size:.9rem'
    : 'margin-top:2rem;padding:1rem 0 .25rem;text-align:center;color:var(--muted);font-size:.9rem;border-top:1px solid rgba(255,255,255,.06)';
?>
<footer style="<?= htmlspecialchars($footerStyle, ENT_QUOTES, 'UTF-8') ?>">
    &copy; <?= htmlspecialchars($copyrightYears, ENT_QUOTES, 'UTF-8') ?>
    <a href="mailto:hichamgi@gmail.com" style="color:inherit;text-decoration:none;font-weight:600">Hicham ARID</a>
</footer>
