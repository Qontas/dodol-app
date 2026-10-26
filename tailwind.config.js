import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            // Diporting dari <script>tailwind.config={...}</script> inline di
            // landing.blade.php (dulu dieksekusi Tailwind Play CDN saat runtime di
            // browser). Dipindah ke sini supaya landing.blade.php bisa lepas dari
            // cdn.tailwindcss.com dan dikompilasi lewat Vite seperti halaman lain.
            keyframes: {
                'gradient-pan': {
                    '0%, 100%': { backgroundPosition: '0% 50%' },
                    '50%': { backgroundPosition: '100% 50%' },
                },
                float: {
                    '0%, 100%': { transform: 'translateY(0)' },
                    '50%': { transform: 'translateY(-12px)' },
                },
            },
            animation: {
                'gradient-pan': 'gradient-pan 12s ease infinite',
                float: 'float 6s ease-in-out infinite',
            },
        },
    },

    plugins: [forms],
};
