<?php

namespace AAM\AddOn\Cli;

use AAM,
    WP_CLI,
    AAM_Core_API,
    AAM_Core_Object_Policy,
    AAM_Service_AccessPolicy;


class Commands
{
    /**
     * Install AAM add-on
     *
     * ## OPTIONS
     *
     * <license>
     * : Valid license key obtained from aamplugin.com
     *
     * ## EXAMPLES
     *
     *  wp aam addon-install AAM000000000000001
     *
     * @param array $args
     * @param array $assoc_args
     *
     * @return void
     *
     * @access public
     * @subcommand addon-install
     */
    public function installAddon($args, $assoc_args)
    {
        $endpoint = AAM_Core_API::getAPIEndpoint() . '/download/' . $args[0];
        $result   = wp_remote_get($endpoint, array(
            'headers' => array(
                'Accept' => 'application/zip',
                'Origin' => site_url()
            )
        ));

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        } elseif ($result['response']['code'] !== 200) {
            $json = json_decode($result['body']);
            WP_CLI::error($json->reason);
        } else {
            $temp = tempnam(sys_get_temp_dir(), 'aam_');

            if (file_put_contents($temp, $result['body'])) {
                WP_Filesystem();
                $res = unzip_file($temp, WP_PLUGIN_DIR);

                if (!is_wp_error($res)) {
                    WP_CLI::success('AAM add-on installed successfully');
                } else {
                    WP_CLI::error($res->get_error_message());
                }
            } else {
                WP_CLI::error('Failed to download the package');
            }
        }
    }

    /**
     * Install AAM access policy
     *
     * ## OPTIONS
     *
     * <id>
     * : Valid policy id obtained from the Policy Hub https://aamplugin.com/access-policy-hub
     *
     * [--users=<users>]
     * : Comma-separated list of user IDs or user emails to whom the current policy will be applied
     *
     * [--roles=<roles>]
     * : Comma-separated list of role slugs to which current policy will be applied
     *
     * [--visitors]
     * : Apply a policy to all visitors (unauthenticated users)
     *
     * [--default]
     * : Apply a policy to everybody (including Administrator role and all admin users)
     *
     * [--exclude-users=<users>]
     * : Comma-separated list of user IDs or user emails to whom the policy will not be applied.
     *
     * [--exclude-roles=<roles>]
     * : Comma-separated list of role slugs to which the policy will not be applied.
     *
     * [--exclude-visitors]
     * : Do not apply the current policy to visitors (unauthenticated users)
     *
     * [--license]
     * : Premium license key that allows to fetch privately hosted policy
     *
     * ## EXAMPLES
     *
     * wp aam policy-install AAM000005 --default --exclude-roles=administrator
     * wp aam policy-install AAM000001 --visitors
     * wp aam policy-install AAM000007 --users=34,john@aamplugin.com,anny@aamplugin.com
     *
     * @param array $args
     * @param array $assoc_args
     *
     * @return void
     *
     * @access public
     * @subcommand policy-install
     */
    public function installPolicy($args, $assoc_args)
    {
        // Get ID
        $id      = array_shift($args);
        $license = (isset($assoc_args['license']) ? $assoc_args['license'] : null);

        // Fetching policy from the policy hub
       // $endpoint = AAM_Core_API::getAPIEndpoint() . '/policy/' . $id;
        $endpoint = 'http://devapi.aamplugin.com/v2/policy/' . $id;

        if (!empty($license)) {
            $endpoint = add_query_arg('license', $license, $endpoint);
        }

        $result = wp_remote_get($endpoint, array(
            'headers' => array(
                'Accept' => 'application/json',
                'Origin' => site_url()
            )
        ));

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        } elseif ($result['response']['code'] !== 200) {
            $json = json_decode($result['body']);
            WP_CLI::error($json->reason);
        } else {
            $json = json_decode($result['body']);

            if (!empty($json)) {
                $id = $this->createPolicy($json);

                if (!is_wp_error($id)) {
                    $this->assignPolicy($id, $assoc_args);
                } else {
                    WP_CLI::error($id->get_error_message());
                }
            }
        }
    }

    /**
     * Undocumented function
     *
     * @param [type] $json
     * @return void
     */
    protected function createPolicy($json)
    {
        $metadata = $json->metadata;

        // Do some basic validation & normalization
        $title    = esc_js($metadata->title);
        $excerpt  = esc_js($metadata->description);

        return wp_insert_post(array(
            'post_type'    => AAM_Service_AccessPolicy::POLICY_CPT,
            'post_content' => wp_json_encode($json->policy, JSON_PRETTY_PRINT),
            'post_title'   => $title,
            'post_excerpt' => $excerpt,
            'post_status'  => 'publish'
        ));
    }

    /**
     * Undocumented function
     *
     * @param [type] $id
     * @param [type] $args
     * @return void
     */
    protected function assignPolicy($id, $args)
    {
        if (!empty($args['users'])) {
            $this->_assignPolicyToUsers($id, $args['users'], true);
        }

        if (!empty($args['roles'])) {
            $this->_assignPolicyToRoles($id, $args['roles'], true);
        }

        if (!empty($args['visitors'])) {
            $this->_assignPolicyToVisitors($id, true);
        }

        if (!empty($args['default'])) {
            $res = AAM::api()->getDefault()->getObject(
                AAM_Core_Object_Policy::OBJECT_TYPE
            )->updateOptionItem($id, true)->save();

            if ($res) {
                WP_CLI::success('Policy attached everybody');
            } else {
                WP_CLI::error('Failed to attach policy to everybody');
            }
        }

        if (!empty($args['exclude-users'])) {
            $this->_assignPolicyToUsers($id, $args['exclude-users'], false);
        }

        if (!empty($args['exclude-roles'])) {
            $this->_assignPolicyToRoles($id, $args['exclude-roles'], false);
        }

        if (!empty($args['exclude-visitors'])) {
            $this->_assignPolicyToVisitors($id, false);
        }
    }

    /**
     * Undocumented function
     *
     * @param [type] $id
     * @param [type] $users
     * @param [type] $effect
     * @return void
     */
    private function _assignPolicyToUsers($id, $users, $effect)
    {
        foreach(explode(',', $users) as $user_id) {
            if (is_numeric($user_id)) {
                $user = get_user_by('ID', $user_id);
            } elseif (filter_var($user_id, FILTER_VALIDATE_EMAIL)) {
                $user = get_user_by('email', $user_id);
            } else {
                $user = new \WP_Error(
                    'invalid_user', 'Invalid user identifier ' . $user_id
                );
            }

            if (!is_wp_error($user)) {
                $res = AAM::api()->getUser($user->ID)->getObject(
                    AAM_Core_Object_Policy::OBJECT_TYPE
                )->updateOptionItem($id, $effect)->save();

                if ($res) {
                    WP_CLI::success('Policy attached to ' . $user->user_email);
                } else {
                    WP_CLI::error(
                        'Failed to attach policy to ' .  $user->user_email
                    );
                }
            } else {
                WP_CLI::error($user->get_error_message());
            }
        }
    }

    /**
     * Undocumented function
     *
     * @param [type] $id
     * @param [type] $roles
     * @param [type] $effect
     * @return void
     */
    private function _assignPolicyToRoles($id, $roles, $effect)
    {
        $wp_roles = wp_roles();

        foreach(explode(',', $roles) as $role_id) {
            if ($wp_roles->is_role($role_id)) {
                $res = AAM::api()->getRole($role_id)->getObject(
                    AAM_Core_Object_Policy::OBJECT_TYPE
                )->updateOptionItem($id, $effect)->save();

                if ($res) {
                    WP_CLI::success('Policy attached to role ' . $role_id);
                } else {
                    WP_CLI::error('Failed to attach policy to role ' .  $role_id);
                }
            } else {
                WP_CLI::error('Role ' . $role_id . ' does not exist');
            }
        }
    }

    /**
     * Undocumented function
     *
     * @param [type] $id
     * @param [type] $effect
     * @return void
     */
    private function _assignPolicyToVisitors($id, $effect)
    {
        $res = AAM::api()->getVisitor()->getObject(
            AAM_Core_Object_Policy::OBJECT_TYPE
        )->updateOptionItem($id, $effect)->save();

        if ($res) {
            WP_CLI::success('Policy attached visitors');
        } else {
            WP_CLI::error('Failed to attach policy to visitors');
        }
    }

}