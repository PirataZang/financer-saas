<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Bot\BotController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ZipCodeController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

// Every route below also passes `throttle:default` (bootstrap/app.php); profiles in config/rate-limit.php.
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:authentication');

// Public (unauthenticated) endpoints: always validated + `throttle:public`. The company is
// identified by its token, so a form only feeds the company it was published for.
Route::prefix('public/{token}')->middleware('throttle:public')->group(function () {
    Route::post('/customers', [CustomerController::class, 'register']);
});

// Telegram bot integration: BotMiddleware (BOT_API_TOKEN + activated Telegram user), limits
// keyed per Telegram user. The bot can read categories and the balancete, and create transactions.
Route::prefix('bot')->group(function () {
    Route::post('/activate', [BotController::class, 'activate'])->middleware(['bot:activation', 'throttle:bot-activation']);

    Route::middleware(['bot', 'throttle:bot'])->group(function () {
        Route::get('/me', [BotController::class, 'me']);
        Route::post('/disconnect', [BotController::class, 'disconnect']);
        Route::get('/finance/categories', [BotController::class, 'categories']);
        Route::get('/finance/summary', [BotController::class, 'summary']);
        Route::post('/finance/transactions', [BotController::class, 'createTransaction'])->middleware('throttle:bot-transactions');
    });
});

// 'company' scopes every query to the user's company (a master may switch with X-Company-Id)
// and blocks inactive companies; 'permission' enforces each action's #[Permission].
Route::middleware(['auth', 'company', 'permission'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'list']);
        Route::get('/{id}', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'create']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::delete('/{id}', [UserController::class, 'delete']);
        Route::put('/{id}/permissions', [UserController::class, 'permissions']);
    });

    Route::prefix('customers')->group(function () {
        Route::get('/', [CustomerController::class, 'list']);
        Route::get('/statuses', [CustomerController::class, 'statuses']);
        Route::get('/{id}', [CustomerController::class, 'index']);
        Route::post('/', [CustomerController::class, 'create']);
        Route::put('/{id}', [CustomerController::class, 'update']);
        Route::delete('/{id}', [CustomerController::class, 'delete']);
    });

    Route::prefix('finance')->group(function () {
        Route::get('/dashboard', [TransactionController::class, 'dashboard']);
        Route::get('/dashboard/export', [TransactionController::class, 'export']);

        Route::prefix('categories')->group(function () {
            Route::get('/', [CategoryController::class, 'list']);
            Route::get('/types', [CategoryController::class, 'types']);
            Route::get('/options', [CategoryController::class, 'options']);
            Route::get('/{id}', [CategoryController::class, 'index']);
            Route::post('/', [CategoryController::class, 'create']);
            Route::put('/{id}', [CategoryController::class, 'update']);
            Route::delete('/{id}', [CategoryController::class, 'delete']);
        });

        Route::prefix('transactions')->group(function () {
            Route::get('/', [TransactionController::class, 'list']);
            Route::get('/options', [TransactionController::class, 'options']);
            Route::get('/{id}', [TransactionController::class, 'index']);
            Route::post('/', [TransactionController::class, 'create']);
            Route::patch('/status', [TransactionController::class, 'status']);
            Route::put('/{id}', [TransactionController::class, 'update']);
            Route::delete('/{id}', [TransactionController::class, 'delete']);
        });
    });

    // Configurações: current company's tabs (one permission each) + the user's own preferences.
    Route::prefix('settings')->group(function () {
        Route::get('/company', [SettingsController::class, 'company']);
        Route::put('/company', [SettingsController::class, 'updateCompany']);
        Route::put('/appearance', [SettingsController::class, 'updateAppearance']);
        Route::get('/finance', [SettingsController::class, 'finance']);
        Route::put('/finance', [SettingsController::class, 'updateFinance']);
        Route::get('/telegram', [SettingsController::class, 'telegram']);
        Route::post('/telegram/code', [SettingsController::class, 'regenerateTelegramCode']);
        Route::delete('/telegram/code', [SettingsController::class, 'revokeTelegramCode']);
        Route::put('/telegram/accounts/{id}', [SettingsController::class, 'updateTelegramAccount']);
        Route::delete('/telegram/accounts/{id}', [SettingsController::class, 'removeTelegramAccount']);
        Route::put('/preferences', [SettingsController::class, 'updatePreferences']);
    });

    Route::get('/zip-codes/{zipCode}', [ZipCodeController::class, 'show']);

    Route::get('/permissions', [PermissionController::class, 'index']);

    Route::get('/activity-log', [ActivityLogController::class, 'index']);

    // Restricted area: masters only (not a permission key, can't be granted).
    Route::middleware('master')->group(function () {
        Route::prefix('companies')->group(function () {
            Route::get('/', [CompanyController::class, 'list']);
            Route::get('/{id}', [CompanyController::class, 'index']);
            Route::post('/', [CompanyController::class, 'create']);
            Route::put('/{id}', [CompanyController::class, 'update']);
            Route::delete('/{id}', [CompanyController::class, 'delete']);
            Route::post('/{id}/token', [CompanyController::class, 'regenerateToken']);
            Route::delete('/{id}/token', [CompanyController::class, 'revokeToken']);
        });

        Route::get('/status', function () {
            $dbConnected = false;
            $dbError = null;
            $dbName = '';

            try {
                DB::connection()->getPdo();
                $dbConnected = true;
                $dbName = DB::connection()->getDatabaseName();
            } catch (\Exception $e) {
                $dbError = $e->getMessage();
            }

            $redisConnected = false;
            $redisError = null;

            try {
                Redis::ping();
                $redisConnected = true;
            } catch (\Exception $e) {
                $redisError = $e->getMessage();
            }

            return response()->json([
                'status' => 'online',
                'database' => $dbConnected ? 'connected' : 'disconnected',
                'db_name' => $dbName,
                'database_error' => $dbError,
                'redis' => $redisConnected ? 'connected' : 'disconnected',
                'redis_error' => $redisError,
            ]);
        });
    });
});
