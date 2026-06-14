<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HandlesZipcodeLookup;

class ZipcodeLookupController extends BaseController
{
    use HandlesZipcodeLookup;
}
