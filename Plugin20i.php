<?php

class Plugin20i extends ServerPlugin
{
    public $features = array(
        'packageName' => true,
        'testConnection' => true,
        'showNameservers' => true,
        'directlink' => true,
        'upgrades' => true
    );

    private $api;
    private $authApi;

    private function setup($args)
    {
        $this->api = new \TwentyI\API\Services($args['server']['variables']['plugin_20i_API_Key']);
        $this->authApi = new \TwentyI\API\Authentication($args['server']['variables']['plugin_20i_OAuth_Client_Key']);
    }


    public function getVariables()
    {
        $variables = [
            lang("Name") => [
                "type" => "hidden",
                "description" => "Used By CE to show plugin - must match how you call the action function names",
                "value" => "20i"
            ],
            lang("Description") => [
                "type" => "hidden",
                "description" => lang("Description viewable by admin in server settings"),
                "value" => lang("20i control panel integration")
            ],
            lang("API Key") => [
                "type" => "text",
                "description" => lang("API Key"),
                "value" => "",
                "encryptable" => true
            ],
            lang("OAuth Client Key") => [
                "type" => "text",
                "description" => lang("OAuth Client Key"),
                "value" => "",
                "encryptable" => true
            ],
            lang("Actions") => [
                "type" => "hidden",
                "description" => lang("Current actions that are active for this plugin per server"),
                "value" => "Create,Delete,Suspend,UnSuspend"
            ],
            lang('reseller')  => [
                'type'          => 'hidden',
                'description'   => lang('Whether this server plugin can set reseller accounts'),
                'value'         => '0',
            ]
        ];

        return $variables;
    }

    public function doCreate($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->create($this->buildParams($userPackage));
        return $userPackage->getCustomField("Domain Name") . ' has been created.';
    }

    public function create($args)
    {
        $this->setup($args);

        $stackUser = $this->findOrCreateStackUser($args);

        $response = $this->api->postWithFields('/reseller/*/addWeb', [
            "type" => $args['package']['name_on_server'],
            "domain_name" => $args['package']['domain_name'],
            "stackUser" => $stackUser,
            // "location" => $dcLocation,
        ]);

        $userPackage = new UserPackage($args['package']['id']);
        $userPackage->setCustomField('Server Acct Properties', $response->result);
    }

    public function doUpdate($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->update($this->buildParams($userPackage, $args));
        return $userPackage->getCustomField("Domain Name") . ' has been updated.';
    }

    public function update($args)
    {
        $this->setup($args);
        if (isset($args['changes']['package'])) {
            $response = $this->servicesAPI->postWithFields(
                "/reseller/*/updatePackage",
                [
                    "id" => [
                        $args['package']['ServerAcctProperties'],
                    ],
                    "packageBundleTypes" => [
                        $this->packageId => $args['changes']['package'],
                    ]
                ]
            );
        }
    }

    public function doDelete($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->delete($this->buildParams($userPackage));
        return $userPackage->getCustomField("Domain Name") . ' has been deleted.';
    }

    public function delete($args)
    {
        $this->setup($args);
        $this->api->postWithFields("/reseller/*/updatePackage", [
            "id" => [$args['package']['ServerAcctProperties']],
            "delete-id" => [$args['package']['ServerAcctProperties']],
        ]);

        $userPackage = new UserPackage($args['package']['id']);
        $userPackage->setCustomField('Server Acct Properties', '');
    }

    public function doSuspend($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->suspend($this->buildParams($userPackage));
        return $userPackage->getCustomField("Domain Name") . ' has been suspended.';
    }

    public function suspend($args)
    {
        $this->setup($args);
        $response = $this->api->postWithFields("/package/{$args['package']['ServerAcctProperties']}/userStatus", [
            "subservices" => [
                "default" => false,
            ],
        ]);
    }

    public function doUnSuspend($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->unsuspend($this->buildParams($userPackage));
        return $userPackage->getCustomField("Domain Name") . ' has been unsuspended.';
    }

    public function unsuspend($args)
    {
        $this->setup($args);
        $response = $this->api->postWithFields("/package/{$args['package']['ServerAcctProperties']}/userStatus", [
            "subservices" => [
                "default" => true,
            ],
        ]);
    }

    public function testConnection($args)
    {
        CE_Lib::log(4, 'Testing connection to 20i');
        $this->setup($args);
        $request = $this->api->getWithFields("/package");
    }

    public function getAvailableActions($userPackage)
    {
        $args = $this->buildParams($userPackage);
        $this->setup($args);

        if ($args['package']['ServerAcctProperties'] == '') {
            $actions[] = 'Create';
            return $actions;
        }

        try {
            $response = $this->api->getWithFields("/package/{$args['package']['ServerAcctProperties']}/");

            if ($response->enabled == 1) {
                $actions[] = 'Suspend';
            } else {
                $actions[] = 'UnSuspend';
            }
            $actions[] = 'Delete';
        } catch (Exception $e) {
            $actions[] = 'Create';
        }

        return $actions;
    }

    public function getDirectLink($userPackage, $getRealLink = true, $fromAdmin = false, $isReseller = false)
    {
        $args = $this->buildParams($userPackage);
        $this->setup($args);

        $stackUser = $this->findStackUser($args);
        if ($stackUser === false) {
            throw new CE_Exception('Can not find Stack User');
        }
        $linkText = $this->user->lang('Login to Panel');

        if ($fromAdmin) {
            $cmd = 'panellogin';
            return [
                'cmd' => $cmd,
                'label' => $linkText
            ];
        } elseif ($getRealLink) {
            $tokenInfo = $this->authApi->controlPanelTokenForUser($stackUser);
            $packageInfo = $this->api->getWithFields("/package/{$args['package']['ServerAcctProperties']}");
            $ssoUrl = $this->api->singleSignOn($tokenInfo->access_token, $packageInfo->name);

            return array(
                'link'    => '<li><a target="_blank" href="' . $ssoUrl . '">' . $linkText . '</a></li>',
                'rawlink' =>  $ssoUrl,
                'form'    => ''
            );
        } else {
            $link = 'index.php?fuse=clients&controller=products&action=openpackagedirectlink&packageId=' . $userPackage->getId() . '&sessionHash=' . CE_Lib::getSessionHash();

            return array(
                'link' => '<li><a target="_blank" href="' . $link .  '">' . $linkText . '</a></li>',
                'form' => ''
            );
        }
    }

    public function dopanellogin($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $response = $this->getDirectLink($userPackage);
        return $response['rawlink'];
    }

    private function findStackUser($args)
    {
        $response = $this->api->getWithFields('/reseller/*/susers');
        foreach ($response->users as $ref => $user) {
            if ($user->name == $args['customer']['email']) {
                return $ref;
            }
        }
        return false;
    }

    private function findOrCreateStackUser($args)
    {
        $user = $this->findStackUser($args);
        if ($user !== false) {
            return $user;
        }

        $user = new User($args['customer']['id']);
        $response = $this->api->postWithFields('/reseller/*/susers', [
            "newUser" => [
                "person_name" => $user->getFirstName() . ' ' . $user->getLastName(),
                "company_name" => $user->getOrganization(),
                "address" => $user->getAddress(),
                "city" => $user->getCity(),
                "sp" => $user->getState(),
                "pc" => $user->getZipCode(),
                "cc" => $user->getCountry(),
                "voice" => $this->validatePhone($user->getPhone(), $user->getCountry()),
                "notes" => null,
                "billing_ref" => null,
                "email" => $user->getEmail(),
                "nominet_contact_type" => null,
            ],
        ]);

        return $response->result->ref;
    }

    private function validatePhone($phone, $country)
    {
        // strip all non numerical values
        $phone = preg_replace('/[^\d]/', '', $phone);

        if ($phone == '') {
            return $phone;
        }

        $query = "SELECT phone_code FROM country WHERE iso=? AND phone_code != ''";
        $result = $this->db->query($query, $country);
        if (!$row = $result->fetch()) {
            return $phone;
        }

        // check if code is already there
        $code = $row['phone_code'];
        $phone = preg_replace("/^($code)(\\d+)/", '+\1.\2', $phone);
        if ($phone[0] == '+') {
            return $phone;
        }

        // if not, prepend it
        return "+$code.$phone";
    }
}
