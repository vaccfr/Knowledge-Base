<?php

namespace BookStack\Http\Controllers\Auth;

use BookStack\Auth\User;
use BookStack\Http\Controllers\Controller;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class VatsimSSOController extends Controller
{
    use AuthenticatesUsers;

    public function login()
    {
        if (Auth::check()) {
            return redirect()->back();
        }
        
        
        $query = http_build_query([
            'client_id' => config('vatsimsso.client_id'),
            'redirect_uri' => config('vatsimsso.redirect'),
            'response_type' => 'code',
            'scope' => 'full_name vatsim_details email',
        ]);

        return redirect(config('vatsimsso.url').'?'.$query);
    }

    public function authenticate(Request $request)
    {
        try {
            $response = (new Client)->post('https://auth.vatsim.net/oauth/token', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => config('vatsimsso.client_id'),
                    'client_secret' => config('vatsimsso.secret'),
                    'redirect_uri' => config('vatsimsso.redirect'),
                    'code' => $request->code,
                ],
            ]);
        } catch (ClientException $th) {
            dd($th);
        }
        $tokens = json_decode((string) $response->getBody(), true);
        try {
            
            $response = (new Client)->get('https://auth.vatsim.net/api/user', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$tokens['access_token'],
                ],
            ]);

        } catch (\Throwable $th) {
            dd($th);
        }

        $response = json_decode($response->getBody());
        
        
        $existingUser = User::where('vatsim_id', (int)$response->data->cid)->first();
        $fname = "-";
        if (isset($response->data->personal->name_first)) {
            $fname = $response->data->personal->name_first;
        }
        $lname = "-";
        if (isset($response->data->personal->name_last)) {
            $lname = $response->data->personal->name_last;
        }
        $email = "-";
        if (isset($response->data->personal->email)) {
            $email = $response->data->personal->email;
        }
        if (is_null($existingUser)) {
            User::create([
                'vatsim_id' => $response->data->cid,
                'name' => $fname.' '.$lname,
                'email' => $email,
                'password' => 'none',
            ]);
            $existingUser = User::where('vatsim_id', $response->data->cid)->first();
            DB::table('role_user')->insert([
                'user_id' => $existingUser->id,
                'role_id' => 4,
            ]);
        } else {
            $existingUser->vatsim_id = $response->data->cid;
            $existingUser->name = $fname.' '.$lname;
            $existingUser->email = $email;
            $existingUser->password = "none";
        }

        Auth::login($existingUser, true);
        return redirect()->route('main');
    }
}
