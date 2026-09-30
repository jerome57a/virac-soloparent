<?php
/**
 * Thin base for every Controller. Deliberately does not reimplement
 * redirect()/base_url()/e() -- those already exist in
 * includes/functions.php and are used by every legacy page too, so
 * Controllers reuse the exact same helpers rather than diverging.
 */
abstract class Controller
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    protected function redirectTo(string $path): void
    {
        redirect(base_url($path));
    }

    /**
     * Renders a view file with $data extracted into local variables.
     * Views are plain PHP templates (same convention as the rest of the
     * app), not a separate template language.
     */
    protected function render(string $viewPath, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require APP_ROOT . '/' . ltrim($viewPath, '/');
    }
}
