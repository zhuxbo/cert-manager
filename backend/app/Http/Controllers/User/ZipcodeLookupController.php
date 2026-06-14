<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Concerns\HandlesZipcodeLookup;

class ZipcodeLookupController extends BaseController
{
    use HandlesZipcodeLookup;
}
