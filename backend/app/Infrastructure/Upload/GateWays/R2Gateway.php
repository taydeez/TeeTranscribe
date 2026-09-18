<?php
/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Infrastructure\Upload\GateWays;

use App\Infrastructure\Upload\R2\R2Client;

class R2Gateway
{

    public function __construct(private readonly R2Client $r2Client){
    }

    public function Presign(array $data){

        $response = $this->r2Client->Presignupload($data);

        return $response;


    }

}
