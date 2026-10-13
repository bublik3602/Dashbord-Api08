<?php
/**
 * Завершення сесії та вихід із системи
 */

require_once __DIR__ . '/includes/auth.php';

Auth::logout();
