<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * #1250：全站不得存在「指向不存在控制器方法」的死路由。
 *
 * 這類路由不會在啟動時報錯——Laravel 只在請求進來時才解析 action，屆時由基底
 * `Illuminate\Routing\Controller::__call` 拋 `BadMethodCallException`，也就是 HTTP 500。
 * 因此它們能長期潛伏，只在被外部掃描或使用者誤點時變成一筆 500 進錯誤日誌。
 *
 * 修這個 issue 時實際清掉 11 條：
 *  - `api/select/codes`（`ApiController` 從來沒有 `codes()`）
 *  - `Route::resource('operations', ...)` 生出的 create／show／edit／update／destroy
 *  - `Route::resource('crowdsourcing', ...)` 生出的同五條
 * 後兩組的根因是「resource 一次生 7 條，但控制器只實作 index 與一個空的 store」——
 * 這是最容易復發的形狀，所以用這條測試守住，而不是只針對個別 URI 斷言 404。
 *
 * 略過 Closure 與 invokable（action 名無 `@`）：後者框架在**註冊時**就以
 * `RouteAction::makeInvokable()` 驗過 `__invoke` 存在，缺就直接拋 UnexpectedValueException，
 * 因此不構成盲區。
 */
class RouteActionsExistTest extends TestCase {
    #[Test]
    public function every_controller_route_points_at_an_existing_method(): void {
        $dead = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            // Closure 路由與 invokable（無 @）不在此測試範圍。
            if ($action === 'Closure' || !str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (!class_exists($class)) {
                $dead[] = sprintf('%s → %s（類別不存在）', $route->uri(), $action);

                continue;
            }

            // 刻意不用 is_callable：基底 Controller 有 __call，is_callable 對任何方法名都會
            // 回 true，正是這個 bug 能潛伏的原因。
            if (!method_exists($class, $method)) {
                $dead[] = sprintf('%s → %s（方法不存在）', $route->uri(), $action);

                continue;
            }

            // method_exists 過關還不夠：它不看可見性，而 dispatch 是由基底
            // Controller::callAction() 以 `$this->{$method}()` 呼叫的，因此
            //   - protected：同一繼承鏈內呼得到 → 正常運作（本庫 Api\ApiController* 底下
            //     有十多條路由就是指向 protected 方法，不能判紅）
            //   - private：基底呼不到 → 落回 __call → BadMethodCallException → 500
            // 所以只擋 private，不要求 public。
            //
            // 刻意**不擋 static**：PHP 允許以實例語法呼叫 static 方法，`$this->{$method}()`
            // 對 public／protected static 都能正常執行（已實測），擋它會對合法的 static
            // action 誤報。abstract 則是真的不可實例化呼叫，仍需擋。
            $reflection = new \ReflectionMethod($class, $method);

            if ($reflection->isPrivate()) {
                $dead[] = sprintf('%s → %s（方法是 private，dispatch 時會落到 __call）', $route->uri(), $action);

                continue;
            }

            if ($reflection->isAbstract()) {
                $dead[] = sprintf('%s → %s（方法是 abstract，無法呼叫）', $route->uri(), $action);

                continue;
            }

            // 指向框架基底自己的方法（middleware()／callAction()／getMiddleware()）也算誤接線：
            // method_exists 會放行，但那不是任何人想要的 action。
            if ($reflection->getDeclaringClass()->getName() === \Illuminate\Routing\Controller::class) {
                $dead[] = sprintf('%s → %s（指向框架基底 Controller 的方法，不是真正的 action）', $route->uri(), $action);
            }
        }

        $this->assertSame(
            [],
            $dead,
            "以下路由指向不存在的控制器方法，命中會 500：\n  ".implode("\n  ", $dead)
                ."\n\n若是 Route::resource 生出來的，請用 ->only([...]) 或 ->except([...]) 收窄到控制器真正實作的動作。"
        );
    }
}
