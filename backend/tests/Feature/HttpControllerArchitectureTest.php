<?php

use Illuminate\Support\Facades\Route;

test('all API endpoints use lean single action controllers', function () {
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }
        $class = $route->getControllerClass();
        expect($class)->not->toBeNull();
        $reflection = new ReflectionClass($class);
        $methods = array_values(array_filter($reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class));
        expect(array_map(fn (ReflectionMethod $method): string => $method->getName(), $methods))->toBe(['__invoke']);
        $source = file_get_contents($reflection->getFileName());
        expect($source)->not->toContain('->validate(', 'response()->', 'Persistence\\Eloquent');
        expect(in_array($route->getActionMethod(), ['__invoke', $class], true))->toBeTrue();
    }
});
