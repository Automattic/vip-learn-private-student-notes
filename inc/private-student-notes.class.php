<?php

class Private_Student_Notes {
    
    /**
     * Constructor method for initializing the class
     * Registers the REST API routes.
     */
    public function __construct() {
        // Register the REST API route
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    /**
     * Check if the current user has permission to edit private notes.
     *
     * @return bool|WP_Error Returns true if the user can edit notes, otherwise a WP_Error.
     */
    private function user_can_edit_private_notes() {
        return true;
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            return in_array( $user->roles[0], [ 'administrator', 'editor', 'subscriber' ], true );
        } else {
            return new WP_Error( 'unauthorized', 'User not logged in', [ 'status' => 401 ] );
        }
    }

    /**
     * Localizes the script with necessary data (nonce for REST API).
     *
     * @return void
     */
    public function localize_script() {
        wp_localize_script(
            'vip-learn-private-student-notes-view-script', // Handle of the script that needs the data
            'wpApiSettings', // The JavaScript object name
            array(
                'nonce' => wp_create_nonce( 'wp_rest' ), // WordPress REST API nonce for security
            )
        );
    }

    /**
     * Renders the private student note editor content on the front-end.
     *
     * @return string The HTML content of the editor or an empty string if the user is not logged in.
     */
    public static function render_private_student_note_editor() {
        if ( ! is_user_logged_in() ) {
            return ''; // Return empty if the user is not logged in
        }
        ob_start();
        echo '<div id="private-student-note-editor" class=""></div>';
        return ob_get_clean();
    }

    /**
     * Registers REST API routes for getting and saving notes.
     *
     * @return void
     */
    public function register_rest_routes() {
        register_rest_route( 'private-student-notes/v1', '/get-note', [
            'methods' => 'GET',
            'callback' => [ $this, 'get_note' ],
            'permission_callback' => function() {
                return $this->user_can_edit_private_notes();
            },
        ] );
        register_rest_route( 'private-student-notes/v1', '/save-note', [
            'methods' => 'POST',
            'callback' => [ $this, 'save_note' ],
            'permission_callback' => function() {
                return $this->user_can_edit_private_notes();
            },
        ] );
    }

    /**
     * Retrieves the private student note via REST API.
     *
     * @return WP_REST_Response The note content or an error if the user is not logged in.
     */
    public function get_note() {
        $user_id = get_current_user_id();
    
        if (!$user_id) {
            return new WP_Error('unauthorized', 'User not logged in', ['status' => 401]);
        }
    
        $note = $this->escape_except_allowed_tags( get_user_meta($user_id, '_private_student_note', true) );
        
        return rest_ensure_response([
            'note' => $note ? $note : '', // Return an empty string if no note exists
        ]);
    }

    /**
     * Saves the private student note via REST API.
     *
     * @param WP_REST_Request $request The REST request object.
     *
     * @return WP_REST_Response The response with success or error message.
     */
    public function save_note( WP_REST_Request $request ) {

        $user_id = get_current_user_id();

        if ( !$user_id ) {
            return new WP_Error('unauthorized', 'User not logged in', ['status' => 401]);
        }

        $note = $this->sanitize_note_content( $request->get_param( 'note' ) );

        $max_length = 10000; // Set the max note character length

        // Check the note length
        if ( strlen( $note ) > $max_length ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Note exceeds the maximum allowed length of ' . $max_length . ' characters.',
            ), 400 );
        }

        if ( empty( $note ) ) {
            return new WP_Error( 'invalid_data', 'Invalid note data', [ 'status' => 400 ] );
        }

        update_user_meta( $user_id, '_private_student_note', $note );

        return new WP_REST_Response( 
            [
                'success' => true,
                'message' => 'Note saved successfully'
            ], 
            200 
        );
    }

    /**
     * Sanitizes the note content, ensuring that only specific HTML tags are allowed.
     * Strips attributes from allowed tags and returns a clean version of the content.
     *
     * @param string $content The content to sanitize.
     *
     * @return string The sanitized content.
     */
    private function sanitize_note_content( $content ) {
        $allowed_tags = array(
            'p'      => array(),
            'em'     => array(),
            'strong' => array(),
            'ul'     => array(),
            'li'     => array(),
        );
    
        $sanitized_content =  wp_kses( $content, $allowed_tags );

        // Strip all attributes from the allowed tags
        $sanitized_content = preg_replace( '/<(p|em|strong|ul|li)\b[^>]*>/', '<$1>', $sanitized_content );

        return $sanitized_content;
    }

    /**
     * Escapes HTML tags in the content, converting unallowed tags to HTML entities
     * while leaving allowed tags intact.
     *
     * @param string $content The content to escape.
     *
     * @return string The content with unallowed tags converted to HTML entities.
     */
    private function escape_except_allowed_tags( $content ) {
        // Define the allowed tags
        $allowed_tags = array(
            'p'      => array(),
            'em'     => array(),
            'strong' => array(),
            'ul'     => array(),
            'li'     => array(),
        );
    
        // Convert all remaining tags to HTML entities
        return preg_replace_callback(
            '/<(\/?)([^>]+)>/',
            function ( $matches ) use ( $allowed_tags ) {
                // If the tag is in the allowed list, return it as is
                $tag_name = strtolower( explode( ' ', $matches[2] )[0] );
                if ( array_key_exists( $tag_name, $allowed_tags ) ) {
                    return $matches[0];
                }
                // Otherwise, escape the tag
                return htmlspecialchars( $matches[0] );
            },
            $content
        );
    }
}
