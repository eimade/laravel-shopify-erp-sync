<?php

namespace Tests\Feature;

use App\Domain\Enums\ConflictStatus;
use App\Domain\Enums\SyncStatus;
use App\Filament\Resources\Conflicts\Pages\ListConflicts;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\SyncRuns\Pages\ListSyncRuns;
use App\Filament\Resources\SyncRuns\Pages\ViewSyncRun;
use App\Filament\Resources\Variants\Pages\EditVariant;
use App\Filament\Resources\Variants\Pages\ListVariants;
use App\Filament\Widgets\ConnectorHealthWidget;
use App\Jobs\PullShopifyCatalog;
use App\Jobs\PushVariantToShopify;
use App\Models\Conflict;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\SyncDispatcher;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->actingAs(User::factory()->create());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        auth()->logout();

        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_dashboard_shows_connector_health(): void
    {
        $this->createSku('ERP-ONLY', inShopify: false);

        $this->get('/admin')->assertOk();
        Livewire::test(ConnectorHealthWidget::class)
            ->assertSee('Santé des connecteurs')
            ->assertSee('Connecté')
            ->assertSee('SKU non publiés');
    }

    public function test_all_resource_pages_render(): void
    {
        $variant = $this->createSku('ROBE-LIN-S');
        $run = app(SyncDispatcher::class)->dispatch(new PushVariantToShopify('ROBE-LIN-S'));

        Livewire::test(ListSyncRuns::class)->assertOk()->assertCanSeeTableRecords([$run]);
        Livewire::test(ViewSyncRun::class, ['record' => $run->id])->assertOk()->assertSee('ROBE-LIN-S');
        Livewire::test(ListVariants::class)->assertOk()->assertCanSeeTableRecords([$variant]);
        Livewire::test(ListProducts::class)->assertOk();
        Livewire::test(ListOrders::class)->assertOk();
        Livewire::test(ListConflicts::class)->assertOk();
    }

    public function test_replay_action_requeues_a_dead_job(): void
    {
        $this->createSku('FOUL-SOIE-01', inShopify: false);
        $dead = app(SyncDispatcher::class)->dispatch(new PushVariantToShopify('FOUL-SOIE-01'));
        $this->fakeShopify()->addProduct('foulard', 'Foulard', [['sku' => 'FOUL-SOIE-01', 'price' => '45.00']]);

        Livewire::test(ListSyncRuns::class)
            ->callAction(TestAction::make('replay')->table($dead))
            ->assertNotified();

        $replay = SyncRun::where('replayed_from_id', $dead->id)->sole();
        $this->assertSame(SyncStatus::Succeeded, $replay->status);
    }

    public function test_conflict_can_be_resolved_from_the_dashboard(): void
    {
        $this->createSku('SERUM-VITC-30', '34.50', shopifyPrice: '29.90');
        app(SyncDispatcher::class)->dispatch(new PullShopifyCatalog);
        $conflict = Conflict::sole();

        Livewire::test(ListConflicts::class)
            ->assertCanSeeTableRecords([$conflict])
            ->callAction(TestAction::make('keep_erp')->table($conflict));

        $this->assertSame(ConflictStatus::Resolved, $conflict->fresh()->status);
        $this->assertSame('34.50', $this->fakeShopify()->findVariantBySku('SERUM-VITC-30')->price);
    }

    public function test_editing_erp_price_pushes_it_to_shopify(): void
    {
        $variant = $this->createSku('ROBE-LIN-S', '79.90');

        Livewire::test(EditVariant::class, ['record' => $variant->id])
            ->fillForm(['price' => '69.90'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('69.90', $this->fakeShopify()->findVariantBySku('ROBE-LIN-S')->price);
    }
}
