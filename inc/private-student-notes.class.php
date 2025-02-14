<?php

class Private_Student_Notes {
    
    /**
     * The allowed tags for the editor, 
     * deliberately strict to mimize tampering.
     */
    private const ALLOWED_TAGS = [
        'p'      => [],
        'em'     => [],
        'strong' => [],
        'ul'     => [],
        'li'     => [],
    ];

    /**
     * The note is stored in a user meta.
     */
    private const NOTE_MAX_LENGTH = 10000;
    
    /**
     * Constructor method for initializing the class
     * Registers the REST API routes.
     */
    public function __construct() {
        // Register the REST API route
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        
        // Ensure that our REST API endpoints are never cached 
        add_filter( 'rest_pre_serve_request', [ $this, 'add_no_cache_headers' ], 10, 3 );
    }

    /**
     * Add no-cache headers using WordPress' built-in nocache_headers().
     *
     * @param bool            $served Whether the request has already been served.
     * @param WP_HTTP_Response $result Result to send to the client.
     * @param WP_REST_Request  $request Request used to generate the response.
     * @return bool Whether the request was served.
     */
    public function add_no_cache_headers( $served, $result, $request ) {
        // Apply no-cache headers only to your custom endpoint
        if ( strpos( $request->get_route(), '/private-student-notes/v1/' ) === 0 ) {
            nocache_headers();
        }
        return $served;
    }

    /**
     * Check if the current user has permission to edit private notes.
     *
     * @return bool|WP_Error Returns true if the user can edit notes, otherwise a WP_Error.
     */
    private function user_can_edit_private_notes() {
        
        /**
         * The nonce is sent in the X-WP-nonce header, 
         * and this is also verified in core rest_cookie_check_errors
         * The additional nonce check here is to make our endpoints
         * return 403 for all non-logged in direct requests 
         * without a valid nonce header before continuing to user authentication.
         */
        
        $nonce = null;

        if( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
            $nonce = sanitize_text_field( $_SERVER['HTTP_X_WP_NONCE'] );
        }

        if ( null === $nonce ) {
            return new WP_Error( 'rest_invalid_nonce', __( 'CSRF check failed' ), [ 'status' => 403 ] );
        }

	    $result = wp_verify_nonce( $nonce, 'wp_rest' );

        if ( !$result ) {
            return new WP_Error( 'rest_invalid_nonce', __( 'CSRF check failed' ), [ 'status' => 403 ] );
        }

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
     * Get the course ID if the current queried object is a course or lesson.
     *
     * @return int The course ID or 0 if not applicable.
     */
    public static function get_course_id() {
        // Check if Sensei LMS is active
        if (!function_exists('Sensei')) {
            return 0;
        }

        // Get the global post object
        global $post;

        // Ensure we have a valid post object
        if (!$post instanceof WP_Post) {
            return 0;
        }

        // Check if the current post is a course
        if ($post->post_type === 'course') {
            return $post->ID;
        }

        // Check if the current post is a lesson and retrieve its associated course
        if ($post->post_type === 'lesson') {
            $course_id = get_post_meta($post->ID, '_lesson_course', true);
            return $course_id ? intval($course_id) : 0;
        }

        // Default to 0 if not a course or lesson
        return 0;
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
        $course_id = self::get_course_id();
        ob_start();
        echo '<div id="private-student-note-editor" data-course-id="' . esc_attr( $course_id ) . '"></div>';
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
            return new WP_Error('unauthorized', 'User not logged in', [ 'status' => 401 ] );
        }

        // Get the user meta key for the note
        $note_key = $this->get_note_meta_key();
    
        $note = $this->escape_except_allowed_tags( get_user_meta( $user_id, $note_key, true ) );
        
        return rest_ensure_response( [
            'note' => $note ? $note : '', // Return an empty string if no note exists
        ] );
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
            return new WP_Error('unauthorized', 'User not logged in', [ 'status' => 401 ]);
        }

        $note = $this->sanitize_note_content( $request->get_param( 'note' ) );

        $max_length = self::NOTE_MAX_LENGTH; // Set the max note character length

        // Check the note length
        if ( strlen( $note ) > $max_length ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => sprintf(
                    __( 'Note exceeds the maximum allowed length of %d characters.', 'vip-learn-private-student-notes' ),
                    $max_length
                ),
            ], 400 );
        }

        if ( empty( $note ) ) {
            return new WP_Error( 'invalid_data', 'Invalid note data', [ 'status' => 400 ] );
        }

        // Get the user meta key for the note
        $note_key = $this->get_note_meta_key();

        // Retrieve the current note
        $current_note = get_user_meta( $user_id, $note_key, true );

        // Check if the new note is the same as the current one
        if ( $current_note === $note ) {
            return new WP_REST_Response( [
                'success' => true,
                'message' => 'No changes were made to the note.',
            ], 200 );
        }

        // Attempt to update the note
        $updated = update_user_meta( $user_id, $note_key, $note );

        if ( $updated ) {
            return new WP_REST_Response( [
                'success' => true,
                'message' => 'Note saved successfully.',
            ], 200 );
        } else {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Failed to save the note. Please try again.',
            ], 500 );
        }
    }


    /**
     * Get the meta key for storing or retrieving private student notes.
     *
     * By default, the method returns the `_vip_learn_private_student_note` meta key.
     * If a valid `X-Course-ID` header is provided, Sensei LMS is active, the course ID is valid,
     * and the logged-in user is enrolled in the course, it returns a course-specific meta key
     * in the format `_vip_learn_private_student_note_<course_id>`.
     *
     * @return string The meta key for the private student note.
     */
    private function get_note_meta_key() {
        // Default meta key
        $default_meta_key = 'vip_learn_private_student_note';

        // Check if Sensei LMS is active
        if (!function_exists('Sensei')) {
            return $default_meta_key;
        }

        // Retrieve the course ID from headers using $_SERVER
        $course_id = 0;
        if (isset($_SERVER['HTTP_X_COURSE_ID'])) {
            $course_id = intval($_SERVER['HTTP_X_COURSE_ID']);
        }

        // If no course ID is provided, return the default meta key
        if (!$course_id) {
            return $default_meta_key;
        }

        // Validate that the course ID is a valid course post
        if (get_post_type($course_id) !== 'course') {
            return $default_meta_key;
        }

        // Check if the logged-in user is enrolled in the course
        $user_id = get_current_user_id();
        $course_enrolment = Sensei_Course_Enrolment::get_course_instance( $course_id );

        if (!$course_enrolment->is_enrolled( $user_id )) {
            return $default_meta_key;
        }

        // Sanitize and return the course-specific meta key
        return sanitize_key($default_meta_key . '_' . $course_id);
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
    
        $sanitized_content =  wp_kses( $content, self::ALLOWED_TAGS );

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
        $allowed_tags = self::ALLOWED_TAGS;
    
        // Convert all remaining tags to HTML entities
        $escaped_content = preg_replace_callback(
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

        return $this->sanitize_note_content( $escaped_content );
    }
}
