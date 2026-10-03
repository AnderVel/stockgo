<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Movimiento;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockGoApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@stockgo.test',
            'password' => Hash::make('secret123'),
            'rol' => 'Administrador',
            'bodega_asignada' => 'Central',
        ]);
    }

    private function operador(): User
    {
        return User::create([
            'name' => 'Operador',
            'username' => 'operador',
            'email' => 'operador@stockgo.test',
            'password' => Hash::make('secret123'),
            'rol' => 'Operador de Almacén',
            'bodega_asignada' => 'Central',
        ]);
    }

    private function producto(array $overrides = []): Producto
    {
        return Producto::create(array_merge([
            'codigo_barras' => '7501234567890',
            'nombre' => 'Producto prueba',
            'unidad_medida' => 'pza',
            'precio' => 10.50,
            'stock_fisico' => 100,
            'stock_reservado' => 0,
            'stock_disponible' => 100,
            'estado' => 'ACTIVO',
            'ubicacion' => 'Pasillo 1',
        ], $overrides));
    }

    public function test_login_devuelve_setup_token_si_2fa_no_configurado(): void
    {
        $admin = $this->admin();

        $response = $this->postJson('/api/auth/login', [
            'usuario' => 'admin',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['status', 'two_factor_setup_required', 'setup_token']);
    }

    public function test_login_rechaza_credenciales_invalidas(): void
    {
        $this->admin();

        $this->postJson('/api/auth/login', [
            'usuario' => 'admin',
            'password' => 'incorrecta',
        ])->assertStatus(401);
    }

    public function test_me_requiere_token_api_access(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['api-access']);

        $this->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('user.username', 'admin');
    }

    public function test_operador_no_puede_crear_productos(): void
    {
        Sanctum::actingAs($this->operador(), ['api-access']);

        $this->postJson('/api/productos', [
            'codigo_barras' => '123',
            'nombre' => 'X',
            'unidad_medida' => 'pza',
            'precio' => 1,
            'stock_fisico' => 1,
            'stock_reservado' => 0,
            'estado' => 'ACTIVO',
        ])->assertStatus(403);
    }

    public function test_entrada_salida_ajuste_mantienen_invariante(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $p = $this->producto();

        $this->postJson('/api/movimientos/entrada', [
            'id_producto' => $p->id_producto,
            'cantidad' => 10,
        ])->assertStatus(201);

        $p->refresh();
        $this->assertSame(110, $p->stock_fisico);
        $this->assertSame(110, $p->stock_disponible);

        $this->postJson('/api/movimientos/salida', [
            'id_producto' => $p->id_producto,
            'cantidad' => 30,
        ])->assertStatus(201);

        $p->refresh();
        $this->assertSame(80, $p->stock_fisico);
        $this->assertSame(80, $p->stock_disponible);

        $this->postJson('/api/movimientos/ajuste', [
            'id_producto' => $p->id_producto,
            'cantidad' => -20,
            'motivo' => 'Merma',
        ])->assertStatus(201);

        $p->refresh();
        $this->assertSame(60, $p->stock_fisico);
        $this->assertGreaterThanOrEqual(0, $p->stock_disponible);
        $this->assertSame(3, Movimiento::count());
    }

    public function test_salida_sin_stock_falla_422(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $p = $this->producto(['stock_fisico' => 5, 'stock_disponible' => 5]);

        $this->postJson('/api/movimientos/salida', [
            'id_producto' => $p->id_producto,
            'cantidad' => 10,
        ])->assertStatus(422);
    }

    public function test_movimiento_picking_no_confia_en_usuario_id_del_body(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $p = $this->producto();

        $this->postJson('/api/inventario/movimiento', [
            'codigo_barras' => $p->codigo_barras,
            'tipo_movimiento' => 'picking',
            'cantidad' => 5,
            'usuario_id' => 99999,
        ])->assertOk()->assertJsonPath('status', 'success');

        $p->refresh();
        $this->assertSame(95, $p->stock_fisico);

        $mov = Movimiento::latest('id_movimiento')->first();
        $this->assertNotNull($mov->usuario_id);
    }

    public function test_proveedor_con_movimientos_no_se_puede_eliminar(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $proveedor = Proveedor::create(['nombre' => 'Prov', 'estado' => 'ACTIVO']);
        $p = $this->producto();

        $this->postJson('/api/movimientos/entrada', [
            'id_producto' => $p->id_producto,
            'cantidad' => 1,
            'id_proveedor' => $proveedor->id_proveedor,
        ])->assertStatus(201);

        $this->deleteJson('/api/proveedores/' . $proveedor->id_proveedor)
            ->assertStatus(409);
    }

    public function test_flujo_completo_de_pedido(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $cliente = Cliente::create(['nombre' => 'Cliente 1', 'estado' => 'ACTIVO']);
        $p = $this->producto();

        $response = $this->postJson('/api/pedidos', [
            'id_cliente' => $cliente->id_cliente,
            'fecha_pedido' => now()->toDateString(),
            'detalles' => [
                ['id_producto' => $p->id_producto, 'cantidad' => 10],
            ],
        ]);

        $response->assertStatus(201);
        $pedidoId = $response->json('id_pedido');

        $p->refresh();
        $this->assertSame(10, $p->stock_reservado);
        $this->assertSame(90, $p->stock_disponible);

        $this->postJson("/api/pedidos/$pedidoId/entregar")->assertStatus(409);

        $this->postJson("/api/pedidos/$pedidoId/surtir")->assertOk();
        $p->refresh();
        $this->assertSame(90, $p->stock_fisico);
        $this->assertSame(0, $p->stock_reservado);

        $this->postJson("/api/pedidos/$pedidoId/entregar")->assertOk();
        $this->postJson("/api/pedidos/$pedidoId/recibir")->assertOk();
    }

    public function test_cancelar_pedido_libera_reserva(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $cliente = Cliente::create(['nombre' => 'Cliente 2', 'estado' => 'ACTIVO']);
        $p = $this->producto();

        $response = $this->postJson('/api/pedidos', [
            'id_cliente' => $cliente->id_cliente,
            'fecha_pedido' => now()->toDateString(),
            'detalles' => [
                ['id_producto' => $p->id_producto, 'cantidad' => 25],
            ],
        ])->assertStatus(201);

        $pedidoId = $response->json('id_pedido');

        $this->postJson("/api/pedidos/$pedidoId/cancelar")->assertOk();

        $p->refresh();
        $this->assertSame(0, $p->stock_reservado);
        $this->assertSame(100, $p->stock_disponible);
    }
}
