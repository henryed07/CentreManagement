/* Centre Management — Admin JS */
jQuery( function ( $ ) {

    $( document ).on( 'click', '.rhcm-media-btn', function ( e ) {
        e.preventDefault();
        var btn     = $( this );
        var inputId = btn.data( 'input' );
        var imgId   = btn.data( 'img' );

        var frame = wp.media( {
            title:    'Select Image',
            button:   { text: 'Use this image' },
            multiple: false,
            library:  { type: 'image' },
        } );

        frame.on( 'select', function () {
            var att = frame.state().get( 'selection' ).first().toJSON();
            $( '#' + inputId ).val( att.url );
            $( '#' + imgId ).attr( 'src', att.url ).show();
            btn.text( 'Change Image' );
            btn.siblings( '.rhcm-media-remove' ).show();
        } );

        frame.open();
    } );

    $( document ).on( 'click', '.rhcm-media-remove', function ( e ) {
        e.preventDefault();
        var btn     = $( this );
        var inputId = btn.data( 'input' );
        var imgId   = btn.data( 'img' );
        $( '#' + inputId ).val( '' );
        $( '#' + imgId ).hide().attr( 'src', '' );
        btn.hide();
        btn.siblings( '.rhcm-media-btn' ).text( 'Select Image' );
    } );

} );
