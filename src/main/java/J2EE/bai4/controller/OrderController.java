package J2EE.bai4.controller;

import J2EE.bai4.dto.OrderCreateForm;
import J2EE.bai4.dto.OrderItemRequest;
import J2EE.bai4.model.Order;
import J2EE.bai4.model.Product;
import J2EE.bai4.service.OrderService;
import J2EE.bai4.service.ProductService;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.ModelAttribute;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;

import java.util.List;
import java.util.stream.Collectors;

@Controller
@RequestMapping("/order")
public class OrderController {

    private final OrderService orderService;
    private final ProductService productService;

    public OrderController(OrderService orderService, ProductService productService) {
        this.orderService = orderService;
        this.productService = productService;
    }

    @GetMapping("")
    public String index(Authentication authentication, Model model) {
        String username = authentication.getName();
        List<Order> orders = orderService.findByAccountUsername(username);
        model.addAttribute("orders", orders);
        return "order/list";
    }

    @GetMapping("/create")
    public String create(Model model) {
        List<Product> products = productService.getAll();
        OrderCreateForm form = new OrderCreateForm();
        products.forEach(p -> form.getItems().add(
                new OrderCreateForm.OrderItemForm(p.getId(), 0)));
        model.addAttribute("orderForm", form);
        model.addAttribute("products", products);
        return "order/create";
    }

    @PostMapping("/create")
    public String create(@ModelAttribute OrderCreateForm orderForm,
                        Authentication authentication,
                        RedirectAttributes redirectAttributes) {
        List<OrderItemRequest> itemRequests = orderForm.getItems().stream()
                .filter(item -> item.getQuantity() != null && item.getQuantity() > 0)
                .map(item -> new OrderItemRequest(item.getProductId(), item.getQuantity()))
                .collect(Collectors.toList());

        try {
            orderService.createOrder(authentication.getName(), itemRequests);
            redirectAttributes.addFlashAttribute("success", "Đặt hàng thành công!");
        } catch (IllegalArgumentException e) {
            redirectAttributes.addFlashAttribute("error", e.getMessage());
            return "redirect:/order/create";
        }
        return "redirect:/order";
    }

    @GetMapping("/{id}")
    public String detail(@PathVariable Integer id, Authentication authentication, Model model) {
        Order order = orderService.getById(id);
        if (order == null) {
            return "redirect:/order";
        }
        if (!order.getAccount().getUsername().equals(authentication.getName())) {
            return "redirect:/order";
        }
        model.addAttribute("order", order);
        return "order/detail";
    }
}
