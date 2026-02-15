protected $middleware = [
    // ... existing middleware
    \App\Http\Middleware\SecurityHeaders::class,
];
protected $middleware = [
    // ... existing middleware
    \App\Http\Middleware\ForceHttps::class,
];