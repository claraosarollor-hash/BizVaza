// Testimonials carousel
(function(){
    var reviews = [];
    var current = 0;
    var timer = null;
    var carousel = document.getElementById('testimonialCarousel');
    var dotsContainer = document.getElementById('testimonialDots');

    function renderStars(rating) {
        var s = '<div class="testimonial-stars">';
        for (var i = 1; i <= 5; i++) {
            s += '<svg viewBox="0 0 24 24" fill="' + (i <= rating ? '#b45309' : 'none') + '" stroke="#b45309" stroke-width="1.6"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
        }
        s += '</div>';
        return s;
    }

    function renderSlide(index) {
        var r = reviews[index];
        return '<div class="testimonial-slide active"><div class="testimonial-stars">' + renderStars(r.rating) + '</div><div class="testimonial-text">' + r.review_text + '</div><div class="testimonial-author">' + r.business_name + '</div><div class="testimonial-badge">Verified User</div></div>';
    }

    function goTo(index) {
        if (!reviews.length) return;
        current = (index + reviews.length) % reviews.length;
        carousel.innerHTML = renderSlide(current);
        var dots = dotsContainer.querySelectorAll('.testimonial-dot');
        dots.forEach(function(d, i) {
            d.classList.toggle('active', i === current);
        });
        resetTimer();
    }

    function resetTimer() {
        if (timer) clearInterval(timer);
        if (reviews.length > 1) {
            timer = setInterval(function() { goTo(current + 1); }, 5000);
        }
    }

    function buildDots() {
        dotsContainer.innerHTML = '';
        for (var i = 0; i < reviews.length; i++) {
            var dot = document.createElement('button');
            dot.className = 'testimonial-dot' + (i === 0 ? ' active' : '');
            dot.setAttribute('aria-label', 'Go to review ' + (i + 1));
            dot.addEventListener('click', function(idx) { return function() { goTo(idx); }; }(i));
            dotsContainer.appendChild(dot);
        }
    }

    fetch('ajax/reviews.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.length) {
                reviews = data;
                buildDots();
                goTo(0);
            } else {
                carousel.innerHTML = '<div class="testimonial-empty">No reviews yet. Be the first!</div>';
            }
        })
        .catch(function() {
            carousel.innerHTML = '<div class="testimonial-empty">Could not load reviews.</div>';
        });
})();
