<?php

namespace App\Http\Controllers\Mship;

use App\Jobs\Mship\Security\SendSecurityTemporaryPasswordEmail;
use App\Jobs\Mship\Security\TriggerPasswordReset;
use App\Jobs\Mship\Security\TriggerPasswordResetConfirmation;
use App\Models\Mship\Account;
use App\Models\Mship\Security as SecurityType;
use App\Models\Sys\Token as SystemToken;
use Auth;
use Bus;
use Carbon\Carbon;
use Input;
use Redirect;
use Session;
use View;

class Security extends \App\Http\Controllers\BaseController {

    public function getAuth() {
        if(Session::has("auth_override")){
            return Redirect::route("mship.auth.redirect");
        }

        // Let's check whether we even NEED this.
        if (Session::has('auth_extra') OR !Auth::user()->current_security OR Auth::user()->current_security == NULL) {
            return Redirect::route("mship.auth.redirect");
        }

        // Next, do we need to replace/reset?
        if (!Auth::user()->current_security->is_active) {
            return Redirect::route("mship.security.replace");
        }

        // So we need it.  Let's go!
        return $this->viewMake("mship.security.auth");
    }

    public function postAuth() {
        if (Auth::user()->current_security->verifyPassword(Input::get("password"))) {
            Session::put('auth_extra', Carbon::now());
            return Redirect::route("mship.auth.redirect");
        }
        return Redirect::route("mship.security.auth")->with("error", "Invalid password entered - please try again.");
    }

    public function getEnable() {
        return Redirect::route("mship.security.replace");
    }

    public function getReplace($disable = false) {
        $currentSecurity = Auth::user()->current_security;

        if ($disable && $currentSecurity && !$currentSecurity->security->optional) {
            return Redirect::route("mship.manage.dashboard")->with("error", "You cannot disable your secondary password.");
        } elseif ($disable && !$currentSecurity) {
            $disable = false;
        } elseif($disable) {
            $this->setTitle("Disable");
        }

        if (!$currentSecurity OR $currentSecurity == NULL) {
            $this->setTitle("Create");
            $slsType = 'requested';
        } else {
            if (strlen($currentSecurity->value) < 1) {
                $this->setTitle("Create");
                $slsType = "forced";
            } elseif (!$currentSecurity->is_active) {
                $slsType = "expired";
            } elseif(!$disable) {
                $slsType = 'replace';
                $this->setTitle("Replace");
            } else {
                $slsType = 'disable';
                $this->setTitle("Disable");
            }
        }

        // Now let's get the requirements
        if ($currentSecurity OR $currentSecurity != NULL) {
            $securityType = $currentSecurity->security;
        }
        if (!$currentSecurity OR $currentSecurity == NULL OR ! $securityType) {
            $securityType = SecurityType::getDefault();
        }

        $requirements = array();
        if ($securityType->length > 0) {
            $requirements[] = "A minimum of " . $securityType->length . " characters.";
        }
        if ($securityType->alpha > 0) {
            $requirements[] = $securityType->alpha . " alphabetical characters.";
        }
        if ($securityType->numeric > 0) {
            $requirements[] = $securityType->numeric . " numeric characters.";
        }
        if ($securityType->symbols > 0) {
            $requirements[] = $securityType->symbols . " symbolic characters.";
        }

        return $this->viewMake("mship.security.replace")->with("sls_type", $slsType)->with("requirements", $requirements)->with("disable", $disable);
    }

    public function postReplace($disable = false) {
        $currentSecurity = Auth::user()->current_security;

        if ($disable && $currentSecurity && !$currentSecurity->security->optional) {
            return Redirect::route("mship.manage.dashboard")->with("error", "You cannot disable your secondary password.");
        }

        if ($currentSecurity && strlen($currentSecurity->value) > 1) {
            if (!Auth::user()->current_security->verifyPassword(Input::get("old_password"))) {
                return Redirect::route("mship.security.replace", [(int)$disable])->with("error", "Your old password is incorrect.  Please try again.");
            }

            if ($disable) {
                $currentSecurity->delete();
                return Redirect::route("mship.manage.dashboard")->with("success", "Your secondary password has been deleted successfully.");
            }

            if (Input::get("old_password") == Input::get("new_password")) {
                return Redirect::route("mship.security.replace")->with("error", "Your new password cannot be the same as your old password.");
            }
        }

        // Check passwords match.
        if (Input::get("new_password") != Input::get("new_password2")) {
            return Redirect::route("mship.security.replace")->with("error", "The two passwords you enter did not match - you must enter your desired password, twice.");
        }
        $newPassword = Input::get("new_password");

        // Does the password meet the requirements?
        if ($currentSecurity OR $currentSecurity != NULL) {
            $securityType = SecurityType::find($currentSecurity->security_id);
        }
        if (!$currentSecurity OR $currentSecurity == NULL OR !$securityType) {
            $securityType = SecurityType::getDefault();
        }

        // Check the minimum length first.
        if (strlen($newPassword) < $securityType->length) {
            return Redirect::route("mship.security.replace")->with("error", "Your password does not meet the requirements [Length > " . $securityType->length . "]");
        }

        // Check the number of alphabetical characters.
        if (preg_match_all("/[a-zA-Z]/", $newPassword) < $securityType->alpha) {
            return Redirect::route("mship.security.replace")->with("error", "Your password does not meet the requirements [Alpha > " . $securityType->alpha . "]");
        }

        // Check the number of numeric characters.
        if (preg_match_all("/[0-9]/", $newPassword) < $securityType->numeric) {
            return Redirect::route("mship.security.replace")->with("error", "Your password does not meet the requirements [Numeric > " . $securityType->numeric . "]");
        }

        // Check the number of symbols characters.
        if (preg_match_all("/[^a-zA-Z0-9]/", $newPassword) < $securityType->symbols) {
            return Redirect::route("mship.security.replace")->with("error", "Your password does not meet the requirements [Symbols > " . $securityType->symbols . "]");
        }

        // All requirements met, set the password!
        Auth::user()->setPassword($newPassword, $securityType);

        Session::put('auth_extra', Carbon::now());
        return Redirect::route("mship.security.auth");
    }

    public function getForgotten() {
        if (!Auth::user()->current_security) {
            return Redirect::route("mship.manage.dashboard");
        }

        Bus::dispatch(new TriggerPasswordResetConfirmation(Auth::user(), false));
        Auth::logout();

        return $this->viewMake("mship.security.forgotten")->with("success", trans("mship.security.forgotten.success")."<br />".trans("general.dialog.youcanclose"));
    }

    public function getForgottenLink($code=null) {
        // Search tokens for this code!
        $token = SystemToken::where("code", "=", $code)->valid()->first();

        // Is it valid? Has it expired? Etc?
        if(!$token){
            return $this->viewMake("mship.security.forgotten")->with("error", "You have provided an invalid password reset token.");
        }

        // Is it valid? Has it expired? Etc?
        if($token->is_used){
            return $this->viewMake("mship.security.forgotten")->with("error", "You have provided an invalid password reset token.");
        }

        // Is it valid? Has it expired? Etc?
        if($token->is_expired){
            return $this->viewMake("mship.security.forgotten")->with("error", "You have provided an invalid password reset token.");
        }

        // Is it related and for the right thing?!
        if(!$token->related OR $token->type != "mship_account_security_reset"){
            return $this->viewMake("mship.security.forgotten")->with("error", "You have provided an invalid password reset token.");
        }

        // Let's now consume this token.
        $token->consume();

        Bus::dispatch(new TriggerPasswordReset($token));

        Auth::logout();
        return $this->viewMake("mship.security.forgotten")->with("success", "A new password has been generated
            for you and emailed to your <strong>primary</strong> VATSIM email.<br />
                You can now close this window.");
    }

}