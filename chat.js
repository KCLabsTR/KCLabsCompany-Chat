jQuery(document).ready(function($){
    var socket = null;
    if (window.WebSocket && kclabsChat.ws_url) {
        socket = new WebSocket(kclabsChat.ws_url);
        socket.onmessage = function(e){
            var data = JSON.parse(e.data);
            $('#chat-body').append('<div>'+data.message+'</div>');
            if (Notification.permission === 'granted') {
                new Notification('New Message', { body: data.message });
            }
        };
    }

    $('#chat-send').on('click', function(){
        var msg = $('#chat-message').val();
        var file = $('#chat-file')[0].files[0];
        if(file){
            var formData = new FormData();
            formData.append('file', file);
            formData.append('action', 'upload_chat_file');
            formData.append('nonce', kclabsChat.nonce);
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(r){
                    if(r.success){
                        if(socket){
                            socket.send(JSON.stringify({ message: msg, file: r.data.url }));
                        }
                    }
                }
            });
        } else {
            if(socket){
                socket.send(JSON.stringify({ message: msg }));
            }
        }
    });
});