<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoMakerMiddleNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_co_maker_accepts_full_middle_name_initial_initial_with_period_or_blank(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'Lourdes', 'last_name' => 'Santos', 'middle_name' => 'Miguel', 'address' => 'Address One',
        ])->assertRedirect();
        $this->assertSame('Miguel', $folder->coMakers()->where('first_name', 'Lourdes')->sole()->middle_name);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'Maria', 'last_name' => 'Reyes', 'middle_name' => 'L', 'address' => 'Address Two',
        ])->assertRedirect();
        $this->assertSame('L', $folder->coMakers()->where('first_name', 'Maria')->sole()->middle_name);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'Pedro', 'last_name' => 'Cruz', 'middle_name' => 'L.', 'address' => 'Address Three',
        ])->assertRedirect();
        $this->assertSame('L.', $folder->coMakers()->where('first_name', 'Pedro')->sole()->middle_name);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'Ana', 'last_name' => 'Garcia', 'address' => 'Address Four',
        ])->assertRedirect();
        $blank = $folder->coMakers()->where('first_name', 'Ana')->sole();
        $this->assertNull($blank->middle_name);
        // No fake placeholder and no double space in the derived full_name.
        $this->assertSame('Ana Garcia', $blank->full_name);
        $this->assertStringNotContainsString('  ', $blank->full_name);
        $this->assertStringNotContainsString('null', mb_strtolower($blank->full_name));
    }

    public function test_edit_can_change_middle_name_between_full_initial_and_blank_without_touching_other_fields(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create([
            'client_folder_id' => $folder->id, 'full_name' => 'Juan Dela Cruz', 'first_name' => 'Juan',
            'middle_name' => null, 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.', 'address' => 'Fixed Address',
        ]);

        // blank -> full middle name
        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.',
            'middle_name' => 'Santos', 'address' => 'Fixed Address',
        ])->assertRedirect();
        $coMaker->refresh();
        $this->assertSame('Santos', $coMaker->middle_name);
        $this->assertSame('Jr.', $coMaker->suffix);
        $this->assertSame('Fixed Address', $coMaker->address);

        // full middle name -> initial
        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.',
            'middle_name' => 'S.', 'address' => 'Fixed Address',
        ])->assertRedirect();
        $coMaker->refresh();
        $this->assertSame('S.', $coMaker->middle_name);
        $this->assertSame('Juan', $coMaker->first_name);
        $this->assertSame('Dela Cruz', $coMaker->last_name);

        // initial -> blank again
        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.',
            'middle_name' => '', 'address' => 'Fixed Address',
        ])->assertRedirect();
        $coMaker->refresh();
        $this->assertNull($coMaker->middle_name);
        $this->assertSame('Fixed Address', $coMaker->address);
        $this->assertSame('Jr.', $coMaker->suffix);
    }
}
