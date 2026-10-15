<?php
/**
 * Сторінка Служби головного енергетика заводу (Блок В: Електроенергія, водопостачання та технологічна пара)
 */

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

// Обов'язкова перевірка доступу до розділу енергетики
$currentUser = Auth::requireAuth('energo');

// Перенаправляємо на головний дашборд, де для ролі 'energo' автоматично відображається виключно Блок В
header('Location: index.php');
exit;
