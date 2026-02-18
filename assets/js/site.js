document.addEventListener('DOMContentLoaded', function(){
    // sidebar toggle button (should have .mobile-toggle class)
    var toggles = document.querySelectorAll('.mobile-toggle');
    toggles.forEach(function(btn){
        btn.addEventListener('click', function(e){
            e.preventDefault();
            document.querySelectorAll('.sidebar').forEach(function(sb){ sb.classList.toggle('open'); });
        });
    });

    // close sidebar when clicking outside on small screens
    document.addEventListener('click', function(e){
        if (window.innerWidth <= 992) {
            var side = document.querySelector('.sidebar');
            if (!side) return;
            if (!side.contains(e.target) && !e.target.closest('.mobile-toggle')) {
                side.classList.remove('open');
            }
        }
    });
});
