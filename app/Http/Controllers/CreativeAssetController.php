<?php

namespace App\Http\Controllers;

use App\Models\CreativeAsset;
use App\Services\CreativeLibrary;
use App\Support\Membership;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CreativeAssetController extends Controller
{
    public function __invoke(Request $request, CreativeAsset $creativeAsset, string $variant, CreativeLibrary $library): Response
    {
        $membership = Membership::of($request->user(), $creativeAsset->workspace_id);
        abort_unless($membership && ! $membership->isClient() && $membership->allowsBrand($creativeAsset->product?->brand_id), 404);

        return $library->deliver($creativeAsset, $variant);
    }
}
