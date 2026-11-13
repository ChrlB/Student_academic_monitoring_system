document.addEventListener('DOMContentLoaded', () => {
    
    // --- 1. Search Functionality ---
    const searchBar = document.querySelector('.search-bar');
    const weekItems = document.querySelectorAll('.week-item');

    if (searchBar) {
        searchBar.addEventListener('input', (e) => {
            const searchTerm = e.target.value.toLowerCase();
            
            weekItems.forEach(item => {
                const topicNameElement = item.querySelector('.topic-name');
                if (topicNameElement) {
                    const topicName = topicNameElement.textContent.toLowerCase();
                    // Keep original grid display if it matches, hide if it doesn't
                    item.style.display = topicName.includes(searchTerm) ? 'grid' : 'none';
                }
            });
        });
    }

    // --- 2. Filter Functionality ---
    const filterItems = document.querySelectorAll('.filter-item');
    let currentFilter = null;

    filterItems.forEach(filter => {
        filter.style.cursor = 'pointer'; // UX improvement
        
        filter.addEventListener('click', (e) => {
            const filterText = filter.textContent.trim().toUpperCase();
            
            // Toggle off if clicking the same filter again
            if (currentFilter === filterText) {
                currentFilter = null;
                filter.style.opacity = '1';
                // Show all items
                weekItems.forEach(item => item.style.display = 'grid');
                return;
            }

            currentFilter = filterText;

            // Visual feedback for active filter (dim others)
            filterItems.forEach(f => f.style.opacity = '0.5');
            filter.style.opacity = '1';

            // Apply filter to rows
            weekItems.forEach(item => {
                const badge = item.querySelector('.status-badge');
                if (badge) {
                    const badgeText = badge.textContent.trim().toUpperCase();
                    if (badgeText === filterText) {
                        item.style.display = 'grid';
                    } else {
                        item.style.display = 'none';
                    }
                }
            });
        });
    });

    // --- 3. Action Buttons (Right Column) ---
    const actionButtons = document.querySelectorAll('.syl-btn');
    actionButtons.forEach(btn => {
        btn.addEventListener('click', (e) => {
            const action = e.target.textContent.trim();
            
            if (action === 'Mark as Done') {
                // Example of visual update before backend integration
                e.target.textContent = 'Updating...';
                setTimeout(() => {
                    e.target.textContent = 'Completed!';
                    e.target.style.backgroundColor = '#00ff00';
                    e.target.style.color = '#000';
                }, 800);
            } else if (action === 'Upload Material') {
                console.log('Triggering file upload modal/dialog...');
                // Trigger file input or modal here
            }
        });
    });
});