/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './src/Resources/**/*.{blade.php,js,vue}',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
