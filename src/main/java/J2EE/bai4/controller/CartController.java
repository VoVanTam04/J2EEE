package J2EE.bai4.controller;

import J2EE.bai4.dto.CartItem;
import J2EE.bai4.model.Product;
import J2EE.bai4.service.CartService;
import J2EE.bai4.service.ProductService;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.*;

@Controller
@RequestMapping("/cart")
public class CartController {

    private final CartService cartService;
    private final ProductService productService;

    public CartController(CartService cartService, ProductService productService) {
        this.cartService = cartService;
        this.productService = productService;
    }

    @GetMapping("")
    public String viewCart(Model model) {
        model.addAttribute("cartItems", cartService.getItems());
        model.addAttribute("totalAmount", cartService.getAmount());
        return "cart/list";
    }

    @GetMapping("/add/{id}")
    public String addCart(@PathVariable("id") int id) {
        Product product = productService.get(id);
        if (product != null) {
            CartItem item = new CartItem(
                product.getId(),
                product.getName(),
                product.getPrice(),
                product.getImage(),
                1
            );
            cartService.add(item);
        }
        return "redirect:/cart";
    }

    @PostMapping("/update")
    public String updateCart(@RequestParam("productId") int productId, @RequestParam("quantity") int quantity) {
        if (quantity <= 0) {
            cartService.remove(productId);
        } else {
            cartService.update(productId, quantity);
        }
        return "redirect:/cart";
    }

    @GetMapping("/remove/{id}")
    public String removeCart(@PathVariable("id") int id) {
        cartService.remove(id);
        return "redirect:/cart";
    }

    @GetMapping("/clear")
    public String clearCart() {
        cartService.clear();
        return "redirect:/cart";
    }
}
