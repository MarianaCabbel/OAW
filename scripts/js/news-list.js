(function () {
    var openButton = document.getElementById('openSettings');
    var closeButton = document.getElementById('closeSettings');
    var modal = document.getElementById('settingsModal');
    var openFilters = document.getElementById('openFilters');
    var closeFilters = document.getElementById('closeFilters');
    var filtersModal = document.getElementById('filtersModal');

    function revealContent() {
        if (document.body) {
            document.body.classList.remove('preload-news');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', revealContent);
    } else {
        revealContent();
    }

    if (!openButton || !closeButton || !modal) {
        return;
    }

    function openModal() {
        modal.classList.add('is-open');
    }

    function closeModal() {
        modal.classList.remove('is-open');
    }

    openButton.addEventListener('click', openModal);
    closeButton.addEventListener('click', closeModal);

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    if (openFilters && closeFilters && filtersModal) {
        openFilters.addEventListener('click', function () {
            filtersModal.classList.add('is-open');
        });

        closeFilters.addEventListener('click', function () {
            filtersModal.classList.remove('is-open');
        });

        filtersModal.addEventListener('click', function (event) {
            if (event.target === filtersModal) {
                filtersModal.classList.remove('is-open');
            }
        });
    }
})();
